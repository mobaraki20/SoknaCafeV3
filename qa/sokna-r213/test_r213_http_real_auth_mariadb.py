#!/usr/bin/env python3
"""CI only: same real app session and database over an actual PHP HTTP socket."""
import os, sys, time, re, json, http.cookiejar, urllib.request, urllib.parse, urllib.error, hashlib, zlib, struct

assert os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('SOKNA_R213_DISPOSABLE_ONLY')=='YES', 'CI only'
base='http://127.0.0.1:18080'
cookies=http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, hdrs, newurl): return None
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies),NoRedirect())

def request(path, method='GET', data=None, content_type=None):
    headers={'User-Agent':'Sokna-R213-QA-Disposable-Only'}
    if content_type: headers['Content-Type']=content_type
    req=urllib.request.Request(base+path,data=data,headers=headers,method=method)
    try:
        with opener.open(req,timeout=30) as r: return r.status,dict(r.headers),r.read()
    except urllib.error.HTTPError as e: return e.code,dict(e.headers),e.read()

def check(cond, name):
    if not cond: raise AssertionError(name)
    print('PASS_R213_'+name,flush=True)

def json_request(path, obj):
    data=json.dumps(obj,ensure_ascii=False).encode('utf-8')
    status,headers,body=request(path,'POST',data,'application/json')
    try: parsed=json.loads(body)
    except Exception: raise AssertionError(f'EXPECTED_JSON status={status}, response={body[:180]}')
    return status,parsed

def snapshot():
    status,h,raw=request('/guest-content/api.php')
    try: obj=json.loads(raw)
    except Exception: raise AssertionError(f'SNAPSHOT_BAD_RESPONSE {status}: {raw[:160]}')
    check(status==200 and obj.get('success') is True,'AUTHENTICATED_SNAPSHOT_HTTP')
    return obj['snapshot']['media']

def png(width=40,height=40):
    # Deterministic valid PNG, unique to R213 CI, no personal or customer images.
    def chunk(tag, dat): return struct.pack('!I',len(dat))+tag+dat+struct.pack('!I',zlib.crc32(tag+dat)&0xffffffff)
    row=bytes([0])+bytes([22,122,103])*width
    rgb=row*height
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR',struct.pack('!2I5B',width,height,8,2,0,0,0)) + chunk(b'IDAT',zlib.compress(rgb)) + chunk(b'IEND',b''))

def multipart(data, csrf, name='r213-qa.png'):
    boundary='----sokna-r213-qa-'+hashlib.sha256(data).hexdigest()[:20]
    body=(f'--{boundary}\r\nContent-Disposition: form-data; name="csrf_token"\r\n\r\n{csrf}\r\n'
          f'--{boundary}\r\nContent-Disposition: form-data; name="alt_text"\r\n\r\nR213 isolated check\r\n'
          f'--{boundary}\r\nContent-Disposition: form-data; name="media"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n').encode()+data+f'\r\n--{boundary}--\r\n'.encode()
    return request('/guest-content/upload.php','POST',body,'multipart/form-data; boundary='+boundary)

for _ in range(30):
    try:
        st,h,body=request('/login.php')
        if st==200:break
    except OSError:pass
    time.sleep(1)
else:raise AssertionError('SERVER_NOT_READY')
check(st==200 and b'name="password"' in body,'REAL_LOGIN_PAGE_LOAD')
st,h,body=request('/guest-content/index.php')
check(st in (301,302,303) and 'login.php' in h.get('Location',''),'UNAUTHENTICATED_ADMIN_PAGE_REDIRECT')
login=urllib.parse.urlencode({'username':'qa_r213_admin','password':os.environ['SOKNA_R213_ADMIN_PASSWORD']}).encode()
st,h,body=request('/login.php','POST',login,'application/x-www-form-urlencoded')
check(st in (302,303) and h.get('Location'),'REAL_ADMIN_LOGIN_SESSION_COOKIE')
st,h,body=request('/guest-content/index.php')
check(st==200 and b'data-guest-content' in body,'REAL_ADMIN_ALBUM_PAGE')
m=re.search(rb'data-guest-content[^>]*data-csrf="([0-9a-f]{64})"',body)
check(m is not None,'REAL_SESSION_CSRF_TOKEN')
csrf=m.group(1).decode()
original=png(); initial=snapshot();size=len(initial)
st,h,raw=multipart(original,'bad-token')
obj=json.loads(raw)
check(st==419 and obj.get('code')=='csrf_expired','REJECT_BAD_CSRF_REAL_SESSION')
st,h,raw=multipart(b'not an image',csrf)
obj=json.loads(raw)
check(st in (400,422) and not obj.get('success'),'REJECT_INVALID_FILE_REAL_HTTP')
st,h,raw=multipart(original,csrf)
obj=json.loads(raw)
check(st==200 and obj.get('success') is True and not obj['result']['deduplicated'],'REAL_MULTIPART_UPLOAD_TO_MARIADB')
key=obj['result']['media_key']
media=snapshot(); matched=[row for row in media if row.get('media_key')==key]
check(len(matched)==1 and int(matched[0]['active'])==1,'FRESH_IMAGE_VISIBLE_IN_REAL_SNAPSHOT')
st,h,raw=multipart(original,csrf)
obj=json.loads(raw)
check(st==200 and obj.get('success') is True and obj['result'].get('deduplicated') is True and obj['result']['media_key']==key,'DEDUP_SAME_KEY_REAL_HTTP')
st,obj=json_request('/guest-content/api.php',{'action':'media_archive','media_key':key,'csrf_token':csrf})
check(st==200 and obj.get('success') is True,'ARCHIVE_REAL_AUTH_CSRF_HTTP')
media=snapshot(); matched=[row for row in media if row.get('media_key')==key]
check(len(matched)==1 and int(matched[0]['active'])==0,'ARCHIVED_IMAGE_NOT_ACTIVE_REAL_SNAPSHOT')
st,h,raw=multipart(original,csrf)
obj=json.loads(raw)
check(st==200 and obj.get('success') is True and obj['result'].get('restored') is True and obj['result']['media_key']==key,'ARCHIVE_REUPLOAD_RESTORED_REAL_HTTP')
media=snapshot();matched=[row for row in media if row.get('media_key')==key]
check(len(matched)==1 and int(matched[0]['active'])==1 and matched[0].get('archived_at') is None,'RESTORE_VISIBLE_REAL_SNAPSHOT')
check(len(media)==size+1,'NO_DUPLICATE_MEDIA_ROW_REAL_DB_SNAPSHOT')
print('PASS_R213_FULL_REAL_SESSION_CSRF_HTTP_MARIADB_MEDIA_CYCLE',flush=True)
