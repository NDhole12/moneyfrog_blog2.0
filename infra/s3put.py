#!/usr/bin/env python3
"""Upload one file to S3 with AWS Signature V4 (PUT Object). Python 3 standard library only.

    s3put.py [--region ap-south-1] [--path-style] [--endpoint URL] <file> <bucket> <key>

Credentials come ONLY from the environment (never argv, never printed):
    AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, optional AWS_SESSION_TOKEN.
Region defaults to AWS_REGION, then ap-south-1. Objects are stored with
x-amz-server-side-encryption: AES256. The payload hash is computed in chunks and
the body is streamed, so large files never sit in memory.
Exit codes: 0 ok, 1 S3 or network error (S3 error code printed), 2 usage/config.
"""
import datetime
import hashlib
import hmac
import http.client
import os
import re
import sys
import urllib.parse

CHUNK = 1024 * 1024
DEFAULT_REGION = "ap-south-1"


def _hmac(key, msg):
    return hmac.new(key, msg.encode("utf-8"), hashlib.sha256).digest()


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(CHUNK), b""):
            h.update(chunk)
    return h.hexdigest()


def uri_encode(s, keep_slash=True):
    return urllib.parse.quote(s, safe="/~" if keep_slash else "~")


def signing_key(secret, date, region, service="s3"):
    k = _hmac(("AWS4" + secret).encode("utf-8"), date)
    k = _hmac(k, region)
    k = _hmac(k, service)
    return _hmac(k, "aws4_request")


def sign_request(method, path, query, headers, payload_hash, access_key, secret_key,
                 region, amz_date, service="s3"):
    """Return (authorization_header_value, signature, signed_headers).

    `headers` must already contain every header to sign (host, x-amz-date,
    x-amz-content-sha256, ...). `query` is a dict of raw (unencoded) params.
    """
    canon = {k.lower().strip(): " ".join(str(v).split()) for k, v in headers.items()}
    names = sorted(canon)
    signed_headers = ";".join(names)
    canonical_query = "&".join(
        "%s=%s" % (uri_encode(k, False), uri_encode(str(v), False)) for k, v in sorted(query.items())
    )
    canonical_request = "\n".join([
        method,
        uri_encode(path),
        canonical_query,
        "".join("%s:%s\n" % (n, canon[n]) for n in names),
        signed_headers,
        payload_hash,
    ])
    date = amz_date[:8]
    scope = "%s/%s/%s/aws4_request" % (date, region, service)
    string_to_sign = "\n".join([
        "AWS4-HMAC-SHA256", amz_date, scope,
        hashlib.sha256(canonical_request.encode("utf-8")).hexdigest(),
    ])
    signature = hmac.new(signing_key(secret_key, date, region, service),
                         string_to_sign.encode("utf-8"), hashlib.sha256).hexdigest()
    auth = "AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s" % (
        access_key, scope, signed_headers, signature)
    return auth, signature, signed_headers


def build_target(bucket, key, region, path_style, endpoint):
    """Return (scheme, host_header, connect_host, port, request_path)."""
    key = key.lstrip("/")
    if endpoint:
        u = urllib.parse.urlsplit(endpoint)
        scheme = u.scheme or "https"
        port = u.port or (443 if scheme == "https" else 80)
        host_header = u.hostname if port in (80, 443) else "%s:%d" % (u.hostname, port)
        return scheme, host_header, u.hostname, port, "/%s/%s" % (bucket, key)
    host = "s3.%s.amazonaws.com" % region
    if path_style:
        return "https", host, host, 443, "/%s/%s" % (bucket, key)
    vhost = "%s.%s" % (bucket, host)
    return "https", vhost, vhost, 443, "/" + key


def put_file(path, bucket, key, region, access_key, secret_key, token="",
             path_style=False, endpoint="", now=None):
    """PUT the file. Returns (http_status, s3_error_code_or_empty)."""
    payload_hash = sha256_file(path)
    size = os.path.getsize(path)
    scheme, host_header, connect_host, port, req_path = build_target(
        bucket, key, region, path_style, endpoint)
    now = now or datetime.datetime.now(datetime.timezone.utc)
    amz_date = now.strftime("%Y%m%dT%H%M%SZ")
    headers = {
        "host": host_header,
        "x-amz-content-sha256": payload_hash,
        "x-amz-date": amz_date,
        "x-amz-server-side-encryption": "AES256",
    }
    if token:
        headers["x-amz-security-token"] = token
    auth, _, _ = sign_request("PUT", req_path, {}, headers, payload_hash,
                              access_key, secret_key, region, amz_date)
    send = {k: v for k, v in headers.items() if k != "host"}
    send["Authorization"] = auth
    send["Content-Length"] = str(size)
    conn_cls = http.client.HTTPSConnection if scheme == "https" else http.client.HTTPConnection
    conn = conn_cls(connect_host, port, timeout=300)
    try:
        with open(path, "rb") as f:
            conn.request("PUT", uri_encode(req_path), body=f, headers=send)
        resp = conn.getresponse()
        body = resp.read(65536).decode("utf-8", "replace")
    finally:
        conn.close()
    code = ""
    if not 200 <= resp.status < 300:
        m = re.search(r"<Code>([A-Za-z0-9_.]+)</Code>", body)
        code = m.group(1) if m else "UnknownError"
    return resp.status, code


def main(argv):
    args = list(argv)
    region = os.environ.get("AWS_REGION") or DEFAULT_REGION
    path_style, endpoint, pos = False, "", []
    i = 0
    while i < len(args):
        a = args[i]
        if a == "--region" and i + 1 < len(args):
            region = args[i + 1]
            i += 2
        elif a == "--endpoint" and i + 1 < len(args):
            endpoint = args[i + 1]
            i += 2
        elif a == "--path-style":
            path_style = True
            i += 1
        elif a.startswith("-"):
            print("s3put: unknown option %s" % a, file=sys.stderr)
            return 2
        else:
            pos.append(a)
            i += 1
    if len(pos) != 3:
        print("usage: s3put.py [--region R] [--path-style] [--endpoint URL] <file> <bucket> <key>",
              file=sys.stderr)
        return 2
    path, bucket, key = pos
    access = os.environ.get("AWS_ACCESS_KEY_ID", "")
    secret = os.environ.get("AWS_SECRET_ACCESS_KEY", "")
    token = os.environ.get("AWS_SESSION_TOKEN", "")
    if not access or not secret:
        print("s3put: AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY not set in the environment",
              file=sys.stderr)
        return 2
    if not os.path.isfile(path):
        print("s3put: not a file: %s" % path, file=sys.stderr)
        return 2
    try:
        status, code = put_file(path, bucket, key, region, access, secret, token, path_style, endpoint)
    except (OSError, http.client.HTTPException) as e:
        print("s3put: network error: %s" % type(e).__name__, file=sys.stderr)
        return 1
    if 200 <= status < 300:
        print("s3put: OK %d %s" % (status, key))
        return 0
    print("s3put: FAILED http=%d code=%s key=%s" % (status, code, key), file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
