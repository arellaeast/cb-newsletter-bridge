# cb-newsletter-bridge

Captain Baltimore Newsletter Bridge — sends the Daily Guide newsletter from The Newsletter Plugin subscriber list via Resend (POST /wp-json/cb-bridge/v1/send-newsletter, X-CB-Secret header; the secret itself lives in a WordPress option, not in this code).
