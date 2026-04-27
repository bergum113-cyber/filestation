# OnlyOffice 설치 및 설정 가이드

이 문서는 웹하드에 OnlyOffice Document Server를 연동하여 브라우저에서 Office 문서를 편집하는 방법을 설명합니다.

## 목차

1. [개요](#개요)
2. [요구사항](#요구사항)
3. [OnlyOffice 설치 (Docker)](#onlyoffice-설치-docker)
4. [웹서버 리버스 프록시 설정](#웹서버-리버스-프록시-설정)
   - [Apache](#apache-설정)
   - [Nginx](#nginx-설정)
5. [웹하드 설정](#웹하드-설정)
6. [연결 테스트](#연결-테스트)
7. [트러블슈팅](#트러블슈팅)
8. [FAQ](#faq)
9. [보안 권장사항](#보안-권장사항)

---

## 개요

### OnlyOffice란?

OnlyOffice Document Server는 오픈소스 오피스 제품군으로, 웹 브라우저에서 Microsoft Office 형식의 문서를 편집할 수 있게 해줍니다.

### 지원 파일 형식

| 종류 | 확장자 |
|------|--------|
| 문서 | docx, doc, odt, txt, rtf, html, epub |
| 스프레드시트 | xlsx, xls, ods, csv |
| 프레젠테이션 | pptx, ppt, odp |

### 작동 방식

```
┌─────────────┐     HTTPS      ┌─────────────┐     HTTP      ┌─────────────────────┐
│   브라우저   │ ───────────► │  웹서버      │ ───────────► │  OnlyOffice Docker  │
│             │ ◄─────────── │  (프록시)    │ ◄─────────── │  (내부 네트워크)     │
└─────────────┘               └─────────────┘               └─────────────────────┘
                                    │
                                    ▼
                              ┌─────────────┐
                              │   웹하드    │
                              │   (PHP)     │
                              └─────────────┘
```

---

## 요구사항

### 하드웨어

| 항목 | 최소 | 권장 |
|------|------|------|
| CPU | 2코어 | 4코어 이상 |
| RAM | 4GB | 8GB 이상 |
| 디스크 | 10GB | 20GB 이상 |

> ⚠️ OnlyOffice는 메모리를 많이 사용합니다. 최소 4GB RAM이 필요합니다.

### 소프트웨어

- **Docker** 20.10 이상 (Docker 설치 필수)
- **웹서버**: Apache 2.4+ 또는 Nginx 1.18+
- **HTTPS 인증서**: Let's Encrypt 또는 상용 인증서 (권장)

### 네트워크

- OnlyOffice 컨테이너가 웹하드 서버에 접근 가능해야 함
- 브라우저가 OnlyOffice에 접근 가능해야 함 (리버스 프록시 통해)

---

## OnlyOffice 설치 (Docker)

### 1. Docker 설치 (설치 안 된 경우)

**Ubuntu/Debian:**
```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# 로그아웃 후 다시 로그인
```

**CentOS/RHEL:**
```bash
sudo yum install -y docker
sudo systemctl start docker
sudo systemctl enable docker
```

**Windows:**
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) 설치

### 2. OnlyOffice 컨테이너 실행

#### 방법 A: JWT 보안 사용 (권장)

```bash
docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_SECRET=여기에_32자_이상의_시크릿키_입력 \
  onlyoffice/documentserver
```

**JWT_SECRET 생성 방법:**
```bash
# Linux/Mac
openssl rand -hex 32

# Windows PowerShell
-join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })
```

#### 방법 B: JWT 비활성화 (간단 설치, 내부망 전용)

```bash
docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_ENABLED=false \
  onlyoffice/documentserver
```

> ⚠️ JWT 비활성화는 보안에 취약합니다. 내부망에서만 사용하세요.

### 3. 설치 확인

OnlyOffice 시작까지 **1~2분** 소요됩니다.

```bash
# 컨테이너 상태 확인
docker ps

# 로그 확인
docker logs onlyoffice

# 직접 접속 테스트 (서버에서)
curl -I http://localhost:8080
# HTTP/1.1 302 또는 200 이면 성공
```

브라우저에서 확인:
```
http://서버IP:8080
```

Welcome 페이지가 나타나면 설치 성공입니다.

### 4. 포트 변경이 필요한 경우

8080 포트가 사용 중이면 다른 포트 사용:

```bash
docker run -d \
  --name onlyoffice \
  -p 9090:80 \
  --restart=always \
  -e JWT_ENABLED=false \
  onlyoffice/documentserver
```

---

## 웹서버 리버스 프록시 설정

HTTPS를 사용하는 경우, 리버스 프록시가 **필수**입니다.
브라우저의 Mixed Content 정책 때문에 HTTPS 페이지에서 HTTP 리소스를 로드할 수 없습니다.

### Apache 설정

#### 1. 필요 모듈 활성화

```bash
# Ubuntu/Debian
sudo a2enmod proxy proxy_http proxy_wstunnel rewrite headers ssl

# CentOS/RHEL - httpd.conf에서 주석 해제
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so
LoadModule proxy_wstunnel_module modules/mod_proxy_wstunnel.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so

# 모듈 활성화 후 Apache 재시작
sudo systemctl restart apache2   # Ubuntu/Debian
sudo systemctl restart httpd     # CentOS/RHEL
# Windows: httpd -k restart
```

#### 2. VirtualHost 설정

웹하드 도메인의 **SSL(443) VirtualHost** 설정 파일을 찾습니다:
- Ubuntu/Debian: `/etc/apache2/sites-available/your-site-ssl.conf`
- CentOS/RHEL: `/etc/httpd/conf.d/ssl.conf`
- Windows: `conf/extra/httpd-vhosts.conf` 또는 `conf/extra/httpd-ssl.conf`

`</VirtualHost>` **바로 위에** 다음 내용을 추가합니다:

```apache
    # ============================================
    # OnlyOffice 리버스 프록시 설정
    # ============================================
    
    # WebSocket 프록시 (실시간 협업 편집용)
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^/oo/(.*) ws://127.0.0.1:8080/$1 [P,L]
    
    # OnlyOffice 메인 프록시
    <Location /oo/>
        ProxyPass http://127.0.0.1:8080/
        ProxyPassReverse http://127.0.0.1:8080/
        ProxyPreserveHost On
        
        # HTTPS 프록시 헤더 설정
        RequestHeader set X-Forwarded-Proto "https"
        RequestHeader set X-Forwarded-Host "your-domain.com"
    </Location>
    
    # OnlyOffice 캐시 프록시 (문서 렌더링용)
    <Location /cache/>
        ProxyPass http://127.0.0.1:8080/cache/
        ProxyPassReverse http://127.0.0.1:8080/cache/
    </Location>
```

#### 3. 설정 값 변경

| 변경 전 | 변경 후 | 설명 |
|---------|---------|------|
| `127.0.0.1:8080` | 실제 OnlyOffice 주소 | 같은 서버면 127.0.0.1, 다른 서버면 해당 IP |
| `your-domain.com` | 실제 도메인 | 웹하드 접속 도메인 |

**예시 (OnlyOffice가 your-onlyoffice-ip에 있는 경우):**
```apache
    RewriteRule ^/oo/(.*) ws://your-onlyoffice-ip:8080/$1 [P,L]
    
    <Location /oo/>
        ProxyPass http://your-onlyoffice-ip:8080/
        ProxyPassReverse http://your-onlyoffice-ip:8080/
        ProxyPreserveHost On
        RequestHeader set X-Forwarded-Proto "https"
        RequestHeader set X-Forwarded-Host "mynas.example.com"
    </Location>
    
    <Location /cache/>
        ProxyPass http://your-onlyoffice-ip:8080/cache/
        ProxyPassReverse http://your-onlyoffice-ip:8080/cache/
    </Location>
```

#### 4. 설정 확인 및 재시작

```bash
# 설정 문법 확인
apachectl configtest   # Linux
httpd -t               # Windows

# Apache 재시작
sudo systemctl restart apache2   # Ubuntu/Debian
sudo systemctl restart httpd     # CentOS/RHEL
httpd -k restart                 # Windows
```

#### 5. 프록시 테스트

```bash
curl -I https://your-domain.com/oo/
# HTTP/2 302 또는 200 이면 성공
```

---

### Nginx 설정

#### 1. 설정 파일 위치

- Ubuntu/Debian: `/etc/nginx/sites-available/your-site`
- CentOS/RHEL: `/etc/nginx/conf.d/your-site.conf`

#### 2. server 블록에 추가

HTTPS server 블록 안에 다음 내용을 추가합니다:

```nginx
    # ============================================
    # OnlyOffice 리버스 프록시 설정
    # ============================================
    
    # OnlyOffice 메인 프록시
    location /oo/ {
        proxy_pass http://127.0.0.1:8080/;
        proxy_http_version 1.1;
        
        # WebSocket 지원
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        
        # 프록시 헤더
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        
        # 타임아웃 설정 (문서 편집 중 연결 유지)
        proxy_connect_timeout 300;
        proxy_send_timeout 300;
        proxy_read_timeout 300;
        send_timeout 300;
        
        # 버퍼 설정
        proxy_buffering off;
        client_max_body_size 100m;
    }
    
    # OnlyOffice 캐시 프록시
    location /cache/ {
        proxy_pass http://127.0.0.1:8080/cache/;
        proxy_http_version 1.1;
        
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
```

#### 3. 설정 값 변경

`127.0.0.1:8080`을 실제 OnlyOffice 서버 주소로 변경하세요.

**예시 (OnlyOffice가 your-onlyoffice-ip에 있는 경우):**
```nginx
    location /oo/ {
        proxy_pass http://your-onlyoffice-ip:8080/;
        # ... 나머지 동일
    }
    
    location /cache/ {
        proxy_pass http://your-onlyoffice-ip:8080/cache/;
        # ... 나머지 동일
    }
```

#### 4. 설정 확인 및 재시작

```bash
# 설정 문법 확인
sudo nginx -t

# Nginx 재시작
sudo systemctl restart nginx
```

#### 5. 프록시 테스트

```bash
curl -I https://your-domain.com/oo/
# HTTP/2 302 또는 200 이면 성공
```

---

## 웹하드 설정

### 1. 시스템 설정 열기

1. 웹하드에 **관리자 계정**으로 로그인
2. 우측 상단 **⚙️ 설정** 클릭
3. **시스템 설정** 탭 선택

### 2. OnlyOffice 설정

| 항목 | 값 | 설명 |
|------|-----|------|
| **OnlyOffice 연동 활성화** | ✅ 체크 | 기능 활성화 |
| **Document Server URL** | `https://도메인/oo` | 리버스 프록시 경로 |
| **JWT 시크릿 키** | Docker 설정과 동일 | JWT_ENABLED=false면 비워둠 |
| **콜백 URL** | (비워둠) | 자동 감지, 필요시만 입력 |

**예시:**
- Document Server URL: `https://mynas.example.com/oo`
- JWT 시크릿 키: `a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6` (Docker와 동일)
- 콜백 URL: (비워둠)

### 3. 연결 테스트

**🔗 연결 테스트** 버튼을 클릭하여 연결 상태를 확인합니다.

성공 시: ✅ 연결 성공
실패 시: ❌ 연결 실패 (원인 확인 필요)

### 4. 저장

**저장** 버튼을 클릭하여 설정을 저장합니다.

---

## 연결 테스트

### 1. OnlyOffice 직접 접속 테스트

```bash
# 서버에서 실행
curl -I http://127.0.0.1:8080/
# 또는
curl -I http://OnlyOffice서버IP:8080/
```

**성공 응답:**
```
HTTP/1.1 302 Moved Temporarily
Location: /welcome/
```

### 2. 리버스 프록시 테스트

```bash
curl -I https://your-domain.com/oo/
```

**성공 응답:**
```
HTTP/2 302
location: https://your-domain.com/welcome/
```

### 3. 문서 편집 테스트

1. 웹하드에서 `.docx`, `.xlsx`, `.pptx` 파일 업로드
2. 파일 더블클릭 또는 우클릭 → **OnlyOffice로 열기**
3. 문서 편집기가 열리면 성공!

---

## 트러블슈팅

### 문제 1: "연결 테스트 실패"

**원인:** OnlyOffice 서버에 연결할 수 없음

**해결:**
```bash
# 1. Docker 컨테이너 상태 확인
docker ps | grep onlyoffice

# 2. 컨테이너가 없으면 시작
docker start onlyoffice

# 3. 로그 확인
docker logs onlyoffice --tail 50

# 4. 포트 확인
curl -I http://localhost:8080
```

### 문제 2: "Mixed Content" 에러 (브라우저 콘솔)

**원인:** HTTPS 페이지에서 HTTP 리소스 로드 시도

**해결:**
1. 리버스 프록시 설정 확인
2. Document Server URL이 `https://도메인/oo` 형식인지 확인
3. `/cache/` 프록시 설정 추가 확인

### 문제 3: "토큰 오류" 또는 "JWT 오류"

**원인:** JWT 시크릿 불일치

**해결:**
```bash
# Docker의 JWT_SECRET 확인
docker exec onlyoffice cat /etc/onlyoffice/documentserver/local.json | grep secret
```

웹하드 설정의 JWT 시크릿과 동일한지 확인하세요.

**JWT 비활성화로 테스트:**
```bash
# 기존 컨테이너 삭제
docker stop onlyoffice
docker rm onlyoffice

# JWT 비활성화로 재실행
docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_ENABLED=false \
  onlyoffice/documentserver
```

### 문제 4: "502 Bad Gateway"

**원인:** 프록시가 OnlyOffice에 연결 못함

**해결:**
1. OnlyOffice 컨테이너 실행 중인지 확인
2. IP 주소와 포트 확인
3. 방화벽 확인

```bash
# 방화벽에서 포트 열기 (Linux)
sudo firewall-cmd --add-port=8080/tcp --permanent
sudo firewall-cmd --reload
```

### 문제 5: "WebSocket 연결 실패"

**원인:** WebSocket 프록시 설정 누락

**해결:**

**Apache:** RewriteRule 설정 확인
```apache
RewriteEngine On
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule ^/oo/(.*) ws://127.0.0.1:8080/$1 [P,L]
```

**Nginx:** proxy_set_header 확인
```nginx
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
```

### 문제 6: "파일을 찾을 수 없습니다"

**원인:** 콜백 URL 설정 문제

**해결:**
1. 콜백 URL을 명시적으로 설정: `https://도메인/`
2. OnlyOffice 컨테이너에서 웹하드에 접근 가능한지 확인:

```bash
docker exec onlyoffice curl -I https://your-domain.com/
```

### 문제 7: "문서가 저장되지 않음"

**원인:** OnlyOffice → 웹하드 콜백 실패

**해결:**
1. 콜백 URL 설정 확인
2. SSL 인증서 문제일 수 있음 (자체 서명 인증서 사용 시)

```bash
# 자체 서명 인증서 허용 (비권장, 테스트용)
docker stop onlyoffice
docker rm onlyoffice

docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_ENABLED=false \
  -e SSL_VERIFY=false \
  onlyoffice/documentserver
```

---

## FAQ

### Q: OnlyOffice를 같은 서버에 설치해야 하나요?

**A:** 아니요, 다른 서버에 설치해도 됩니다. 단, 네트워크로 서로 접근 가능해야 합니다.

### Q: HTTP만 사용해도 되나요?

**A:** 가능하지만 권장하지 않습니다.
- 내부망 전용이면 HTTP 가능
- 외부 접속이면 HTTPS 필수 (보안 + Mixed Content 문제)

### Q: 여러 웹하드에서 하나의 OnlyOffice를 공유할 수 있나요?

**A:** 네, 가능합니다. OnlyOffice는 여러 사이트에서 공유할 수 있습니다.

### Q: 동시 편집 인원 제한이 있나요?

**A:** Community Edition은 20명 동시 편집 제한이 있습니다. 더 필요하면 Enterprise Edition을 사용하세요.

### Q: OnlyOffice 업데이트는 어떻게 하나요?

```bash
# 기존 컨테이너 중지 및 삭제
docker stop onlyoffice
docker rm onlyoffice

# 최신 이미지 다운로드
docker pull onlyoffice/documentserver

# 새 컨테이너 실행 (기존과 동일한 옵션으로)
docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_SECRET=your-secret-key \
  onlyoffice/documentserver
```

### Q: 데이터는 어디에 저장되나요?

**A:** OnlyOffice는 임시 파일만 저장하며, 실제 문서는 웹하드에 저장됩니다. 컨테이너를 삭제해도 문서는 안전합니다.

### Q: 메모리 사용량을 줄일 수 있나요?

```bash
# 리소스 제한 설정
docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  --memory=2g \
  -e JWT_ENABLED=false \
  onlyoffice/documentserver
```

---

## 보안 권장사항

### 1. JWT 활성화

프로덕션 환경에서는 반드시 JWT를 활성화하세요:

```bash
docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_SECRET=$(openssl rand -hex 32) \
  onlyoffice/documentserver
```

### 2. 내부 포트만 노출

OnlyOffice 포트(8080)는 외부에 직접 노출하지 마세요. 리버스 프록시만 접근하도록 설정:

```bash
# localhost만 바인딩
docker run -d \
  --name onlyoffice \
  -p 127.0.0.1:8080:80 \
  --restart=always \
  -e JWT_SECRET=your-secret-key \
  onlyoffice/documentserver
```

### 3. HTTPS 사용

모든 통신은 HTTPS를 통해 암호화하세요.

### 4. 방화벽 설정

OnlyOffice 포트는 웹서버에서만 접근 가능하도록 방화벽 설정:

```bash
# 예: 웹서버 IP만 허용
sudo iptables -A INPUT -p tcp --dport 8080 -s 웹서버IP -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 8080 -j DROP
```

---

## 부록: 전체 설정 예시

### Apache + OnlyOffice (같은 서버)

**docker 실행:**
```bash
docker run -d \
  --name onlyoffice \
  -p 127.0.0.1:8080:80 \
  --restart=always \
  -e JWT_SECRET=a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6 \
  onlyoffice/documentserver
```

**Apache VirtualHost (443):**
```apache
<VirtualHost *:443>
    ServerName mynas.example.com
    DocumentRoot /var/www/webhard
    
    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem
    
    # PHP 설정
    <Directory /var/www/webhard>
        AllowOverride All
        Require all granted
    </Directory>
    
    # OnlyOffice 리버스 프록시
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^/oo/(.*) ws://127.0.0.1:8080/$1 [P,L]
    
    <Location /oo/>
        ProxyPass http://127.0.0.1:8080/
        ProxyPassReverse http://127.0.0.1:8080/
        ProxyPreserveHost On
        RequestHeader set X-Forwarded-Proto "https"
        RequestHeader set X-Forwarded-Host "mynas.example.com"
    </Location>
    
    <Location /cache/>
        ProxyPass http://127.0.0.1:8080/cache/
        ProxyPassReverse http://127.0.0.1:8080/cache/
    </Location>
</VirtualHost>
```

**웹하드 설정:**
- Document Server URL: `https://mynas.example.com/oo`
- JWT 시크릿 키: `a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6`
- 콜백 URL: (비워둠)

---

### Nginx + OnlyOffice (다른 서버)

**OnlyOffice 서버 (your-onlyoffice-ip):**
```bash
docker run -d \
  --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_ENABLED=false \
  onlyoffice/documentserver
```

**Nginx 설정:**
```nginx
server {
    listen 443 ssl http2;
    server_name mynas.example.com;
    
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    
    root /var/www/webhard;
    index index.php;
    
    # PHP 처리
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # OnlyOffice 프록시
    location /oo/ {
        proxy_pass http://your-onlyoffice-ip:8080/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_connect_timeout 300;
        proxy_send_timeout 300;
        proxy_read_timeout 300;
        proxy_buffering off;
        client_max_body_size 100m;
    }
    
    location /cache/ {
        proxy_pass http://your-onlyoffice-ip:8080/cache/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```

**웹하드 설정:**
- Document Server URL: `https://mynas.example.com/oo`
- JWT 시크릿 키: (비워둠)
- 콜백 URL: `https://mynas.example.com/`

---

## 문의 및 지원

문제가 해결되지 않으면:

1. Apache/Nginx 에러 로그 확인
2. Docker 로그 확인: `docker logs onlyoffice`
3. 브라우저 개발자 도구 (F12) 콘솔 확인
4. GitHub Issues에 문의

---

*마지막 업데이트: 2026-01-24*
