"""Run: python3 -m unittest infra/test_s3put.py   (standard library only)"""
import contextlib
import datetime
import hashlib
import io
import os
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import s3put  # noqa: E402

# Public example credentials from the AWS Signature V4 documentation.
ACCESS = "AKIAIOSFODNN7EXAMPLE"
SECRET = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
EMPTY = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"


class SignerVectors(unittest.TestCase):
    def test_aws_docs_get_object_example(self):
        headers = {
            "Host": "examplebucket.s3.amazonaws.com",
            "Range": "bytes=0-9",
            "x-amz-content-sha256": EMPTY,
            "x-amz-date": "20130524T000000Z",
        }
        auth, sig, signed = s3put.sign_request(
            "GET", "/test.txt", {}, headers, EMPTY, ACCESS, SECRET, "us-east-1", "20130524T000000Z")
        self.assertEqual(sig, "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41")
        self.assertEqual(signed, "host;range;x-amz-content-sha256;x-amz-date")
        self.assertIn("Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request", auth)

    def test_aws_docs_put_object_example(self):
        payload = hashlib.sha256(b"Welcome to Amazon S3.").hexdigest()
        headers = {
            "Host": "examplebucket.s3.amazonaws.com",
            "Date": "Fri, 24 May 2013 00:00:00 GMT",
            "x-amz-date": "20130524T000000Z",
            "x-amz-storage-class": "REDUCED_REDUNDANCY",
            "x-amz-content-sha256": payload,
        }
        _, sig, _ = s3put.sign_request(
            "PUT", "/test$file.text", {}, headers, payload, ACCESS, SECRET, "us-east-1", "20130524T000000Z")
        self.assertEqual(sig, "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd")

    def test_sha256_file_chunked(self):
        data = b"a" * (s3put.CHUNK + 5)
        with tempfile.NamedTemporaryFile(delete=False) as f:
            f.write(data)
            name = f.name
        try:
            self.assertEqual(s3put.sha256_file(name), hashlib.sha256(data).hexdigest())
        finally:
            os.unlink(name)


class _Handler(BaseHTTPRequestHandler):
    log = []
    fail = None

    def do_PUT(self):
        n = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(n)
        type(self).log.append((self.path, dict(self.headers), body))
        if type(self).fail:
            data = ("<Error><Code>%s</Code><Message>x</Message></Error>" % type(self).fail).encode()
            self.send_response(403)
        else:
            data = b""
            self.send_response(200)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *a):
        pass


class PutPath(unittest.TestCase):
    def setUp(self):
        _Handler.log = []
        _Handler.fail = None
        self.srv = HTTPServer(("127.0.0.1", 0), _Handler)
        self.port = self.srv.server_address[1]
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()
        self.tmp = tempfile.NamedTemporaryFile(delete=False)
        self.tmp.write(b"hello backup")
        self.tmp.close()
        self.ep = "http://127.0.0.1:%d" % self.port

    def tearDown(self):
        self.srv.shutdown()
        self.srv.server_close()
        os.unlink(self.tmp.name)

    def test_put_ok_signed_and_encrypted(self):
        now = datetime.datetime(2026, 10, 25, 1, 2, 3, tzinfo=datetime.timezone.utc)
        st, code = s3put.put_file(self.tmp.name, "bkt", "blog-backups/a b.txt", "ap-south-1",
                                  ACCESS, SECRET, token="TOK", endpoint=self.ep, now=now)
        self.assertEqual((st, code), (200, ""))
        path, h, body = _Handler.log[0]
        self.assertEqual(path, "/bkt/blog-backups/a%20b.txt")
        self.assertEqual(body, b"hello backup")
        h = {k.lower(): v for k, v in h.items()}
        self.assertEqual(h["x-amz-server-side-encryption"], "AES256")
        self.assertEqual(h["x-amz-content-sha256"], hashlib.sha256(b"hello backup").hexdigest())
        self.assertEqual(h["x-amz-security-token"], "TOK")
        self.assertEqual(h["x-amz-date"], "20261025T010203Z")
        self.assertIn("Credential=%s/20261025/ap-south-1/s3/aws4_request" % ACCESS, h["authorization"])
        self.assertNotIn(SECRET, h["authorization"])
        hdrs = {"host": "127.0.0.1:%d" % self.port, "x-amz-content-sha256": h["x-amz-content-sha256"],
                "x-amz-date": h["x-amz-date"], "x-amz-server-side-encryption": "AES256",
                "x-amz-security-token": "TOK"}
        _, sig, _ = s3put.sign_request("PUT", "/bkt/blog-backups/a b.txt", {}, hdrs,
                                       h["x-amz-content-sha256"], ACCESS, SECRET, "ap-south-1",
                                       h["x-amz-date"])
        self.assertTrue(h["authorization"].endswith("Signature=" + sig))

    def test_error_code_reported(self):
        _Handler.fail = "SignatureDoesNotMatch"
        st, code = s3put.put_file(self.tmp.name, "bkt", "k", "ap-south-1", ACCESS, SECRET, endpoint=self.ep)
        self.assertEqual((st, code), (403, "SignatureDoesNotMatch"))

    def test_main_exit_codes_and_no_secret_output(self):
        os.environ["AWS_ACCESS_KEY_ID"] = ACCESS
        os.environ["AWS_SECRET_ACCESS_KEY"] = SECRET
        try:
            out, err = io.StringIO(), io.StringIO()
            with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                self.assertEqual(s3put.main(["--endpoint", self.ep, self.tmp.name, "bkt", "k1"]), 0)
                _Handler.fail = "AccessDenied"
                self.assertEqual(s3put.main(["--endpoint", self.ep, self.tmp.name, "bkt", "k2"]), 1)
            self.assertIn("AccessDenied", err.getvalue())
            for s in (out.getvalue(), err.getvalue()):
                self.assertNotIn(SECRET, s)
            del os.environ["AWS_SECRET_ACCESS_KEY"]
            with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                self.assertEqual(s3put.main([self.tmp.name, "bkt", "k3"]), 2)
        finally:
            os.environ.pop("AWS_ACCESS_KEY_ID", None)
            os.environ.pop("AWS_SECRET_ACCESS_KEY", None)

    def test_virtual_hosted_and_path_style_targets(self):
        vh = s3put.build_target("b", "k/x", "ap-south-1", False, "")
        self.assertEqual((vh[1], vh[4]), ("b.s3.ap-south-1.amazonaws.com", "/k/x"))
        ps = s3put.build_target("b", "k/x", "ap-south-1", True, "")
        self.assertEqual((ps[1], ps[4]), ("s3.ap-south-1.amazonaws.com", "/b/k/x"))


if __name__ == "__main__":
    unittest.main()
