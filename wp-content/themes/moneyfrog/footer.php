<?php
/** Site shell bottom: footer ported from moneyfrog.in, Resources pointing at this blog. */

if (!defined('ABSPATH')) {
    exit;
}

$logo    = get_template_directory_uri() . '/assets/img/moneyfrog-logo.svg';
$site    = static fn (string $path = ''): string => mf_site_url($path);
$socials = [
    ['LinkedIn', 'i-linkedin', false, 'https://www.linkedin.com/company/moneyfrog-in'],
    ['Instagram', 'i-instagram', true, 'https://www.instagram.com/moneyfrog_financial_services/'],
    ['Facebook', 'i-facebook', false, 'https://www.facebook.com/moneyfrog.in'],
    ['YouTube', 'i-youtube', false, 'https://www.youtube.com/@moneyfrogfinancialservices4924'],
    ['X', 'i-x', false, 'https://x.com/moneyfrog_in'],
];
$legal = [
    ['Privacy Policy', $site('/privacy-policy'), false],
    ['Terms & Conditions', $site('/terms-and-conditions'), false],
    ['Commission Disclosure', $site('/commission-disclosure'), false],
    ['SEBI Circulars', 'https://www.sebi.gov.in/sebiweb/home/HomeAction.do?doListing=yes&sid=1&ssid=7&smid=0', true],
    ['MF/SAI / SID / Factsheets / Others', $site('/scheme-documents'), false],
    ['Registrations & Certifications', $site('/registrations-certifications'), false],
    ['Website Disclaimer', $site('/disclaimer'), false],
    ['Grievance Redressal', $site('/grievance-redressal'), false],
];
?>
<footer class="footer" id="footer">
    <div class="wrap">
        <div class="footer__grid">
            <div class="footer__brand">
                <a href="<?php echo esc_url($site('/')); ?>" class="brand"><img src="<?php echo esc_url($logo); ?>" alt="Moneyfrog" width="201" height="35"></a>
                <p class="footer__about">Trust, technology, wealth, reimagined.</p>
                <div class="footer__contact">
                    <p class="footer__line">
                        <?php echo mf_icon('i-pin'); ?>
                        <span><strong>India Head Office</strong>723 &amp; 724, 7th Floor, Swastik Chambers,<br>SG Barve Marg, Chembur,<br>Mumbai, Maharashtra 400071</span>
                    </p>
                    <p class="footer__line">
                        <?php echo mf_icon('i-phone'); ?>
                        <a href="tel:+919004932221">+91 90049 32221</a>
                    </p>
                    <p class="footer__line">
                        <?php echo mf_icon('i-whatsapp', 'icon icon--fill'); ?>
                        <a href="https://wa.me/919004675553" target="_blank" rel="noopener">+91 90046 75553</a>
                    </p>
                    <p class="footer__line">
                        <?php echo mf_icon('i-mail'); ?>
                        <a href="mailto:contact@moneyfrog.in">contact@moneyfrog.in</a>
                    </p>
                </div>
            </div>

            <nav class="footer__links" aria-label="Offerings">
                <p class="footer__head">Offerings</p>
                <a href="<?php echo esc_url($site('/mutual-funds')); ?>">Mutual Funds</a>
                <a href="<?php echo esc_url($site('/stocks-equity')); ?>">Equity</a>
                <a href="<?php echo esc_url($site('/fixed-income')); ?>">Fixed Income</a>
                <a href="<?php echo esc_url($site('/aif-gift-city')); ?>">AIF / GIFT City</a>
                <a href="<?php echo esc_url($site('/pms')); ?>">PMS</a>
                <a href="<?php echo esc_url($site('/tax')); ?>">Tax</a>
                <a href="<?php echo esc_url($site('/insurance')); ?>">Insurance</a>
            </nav>

            <nav class="footer__links" aria-label="Explore Moneyfrog">
                <p class="footer__head">Explore Moneyfrog</p>
                <a href="<?php echo esc_url($site('/about-us')); ?>">About Us</a>
                <a href="<?php echo esc_url($site('/#accounts')); ?>">Who We Serve</a>
                <a href="<?php echo esc_url($site('/contact-us')); ?>">Careers</a>
                <a href="<?php echo esc_url($site('/login')); ?>">Client Login</a>
                <a href="<?php echo esc_url($site('/signup')); ?>">Sign Up</a>
                <a href="<?php echo esc_url($site('/contact-us')); ?>">Contact Us</a>
            </nav>

            <nav class="footer__links" aria-label="Resources">
                <p class="footer__head">Resources</p>
                <a href="<?php echo esc_url(home_url('/')); ?>">Blogs</a>
                <a href="<?php echo esc_url(home_url('/category/market-outlook/')); ?>">Market Outlook</a>
                <a href="<?php echo esc_url($site('/faq')); ?>">FAQs</a>
                <a href="<?php echo esc_url($site('/#calculator')); ?>">Financial Calculators</a>
            </nav>
        </div>

        <div class="footer__connect">
            <div class="footer__follow">
                <p class="footer__subhead">Follow Us</p>
                <div class="social">
                    <?php foreach ($socials as [$label, $icon, $stroke, $url]) : ?>
                        <a href="<?php echo esc_url($url); ?>" class="social__link--<?php echo esc_attr(strtolower($label)); ?>" target="_blank" rel="noopener" aria-label="Moneyfrog on <?php echo esc_attr($label); ?>"><?php echo mf_icon($icon, $stroke ? 'icon' : 'icon icon--fill'); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="footer__download">
                <p class="footer__subhead">Download the Moneyfrog App</p>
                <div class="apps">
                    <a class="app-badge" href="https://play.google.com/store/apps/details?id=com.myapp.moneyfrog.moneyfrogapp&amp;hl=en" target="_blank" rel="noopener">
                        <?php echo mf_icon('i-google-play', 'icon icon--fill'); ?>
                        <span><small>Get it on</small>Google Play</span>
                    </a>
                    <a class="app-badge" href="https://apps.apple.com/us/app/moneyfrog-in/id1079405996?ls=1" target="_blank" rel="noopener">
                        <?php echo mf_icon('i-apple', 'icon icon--fill'); ?>
                        <span><small>Download on the</small>App Store</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="footer__legal">
            <nav class="footer__legal-links" aria-label="Legal">
                <?php foreach ($legal as [$label, $url, $external]) : ?>
                    <a href="<?php echo esc_url($url); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($label); ?><?php echo $external ? '<span class="sr-only"> (opens in a new tab)</span>' : ''; ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="footer__regs">
                <p class="footer__reg">Moneyfrog Financial Services Private Limited <span aria-hidden="true">|</span> CIN: U66000MH2011PTC216613 <span aria-hidden="true">|</span> GSTIN: 27AAJCA5151G1ZR</p>
                <p class="footer__reg">AMFI Registered Mutual Fund Distributor: ARN 94798 <span aria-hidden="true">|</span> IRDAI Registered Corporate Agent: CA0450</p>
            </div>
            <div class="footer__notice">
                <p class="footer__office">Corporate Office: MONEYFROG FINANCIAL SERVICES, 723 &amp; 724, 7th Floor, Swastik Chambers, SG Barve Marg, Chembur, Mumbai, Maharashtra 400071</p>
                <p class="footer__disclaimer">Mutual Fund Disclaimer: Mutual Fund investments are subject to market risks. Please read all scheme related documents carefully before investing. Past performance is not an indicator of future returns. Insurance Disclaimer: Insurance is a subject matter of the solicitation. For more details on policy terms, conditions, exclusions, limitations, please refer/read policy brochure carefully before concluding sale. Moneyfrog's regulatory registrations, certifications, and official credentials are available under <a href="<?php echo esc_url($site('/registrations-certifications')); ?>">Registrations &amp; Certifications</a>.</p>
            </div>
            <p class="footer__copy">&copy; <?php echo esc_html(gmdate('Y')); ?> Moneyfrog Financial Services Private Limited. All rights reserved.</p>
        </div>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
