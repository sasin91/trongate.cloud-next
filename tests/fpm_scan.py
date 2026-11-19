"""Exercise SSH scanning through the real authenticated PHP-FPM web entry point."""
import argparse
import http.cookiejar
import json
import re
import ssl
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--url', required=True)
parser.add_argument('--credentials', required=True)
parser.add_argument('--insecure', action='store_true')
args = parser.parse_args()
with open(args.credentials) as source:
    credentials = json.load(source)
context = ssl._create_unverified_context() if args.insecure else ssl.create_default_context()
opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
    urllib.request.HTTPSHandler(context=context))

def request(path, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    with opener.open(args.url.rstrip('/') + path, data=body, timeout=30) as response:
        return response.read().decode()

page = request('/login/login/1')
csrf = re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
request('/login/submit_login/1', {'csrf_token': csrf, 'identifier': credentials['name'], 'password': credentials['password']})
page = request('/operations', {'csrf_token': csrf, 'command': 'server-scan', 'payload': json.dumps({
    'host': '127.0.0.1', 'port': 22, 'user': 'root', 'credential': 'http-scan-probe'})})
assert 'SHA256:' in page and '&quot;enrollment&quot;' in page, 'HTTP SSH scan did not return a host fingerprint'
request('/login/logout', {'csrf_token': csrf})
print('PASS: authenticated PHP-FPM SSH scan returned a host fingerprint')
