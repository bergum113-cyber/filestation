/**
 * FileStation E2E Encryption Module v2 (Chunked)
 * 
 * AES-256-GCM + PBKDF2 기반 클라이언트 사이드 암호화
 * 대용량 파일 지원: 5MB 청크 단위 암호화/복호화
 * 메모리 사용량: 항상 ~10MB 이내 (청크 크기 x 2)
 * 
 * 저장 구조:
 * - .vault.json: { salt, passwordValidator }
 * - uuid.meta.enc: 암호화된 메타 (파일명/크기/MIME/청크수)
 * - uuid.enc: 단일 암호화 파일 (<=5MB, 하위 호환)
 * - uuid.enc.0 ~ uuid.enc.N: 청크별 암호화 파일 (>5MB)
 */
const E2ECrypto = {

    PBKDF2_ITERATIONS: 100000,
    KEY_LENGTH: 256,
    IV_LENGTH: 12,
    SALT_LENGTH: 32,
    VALIDATOR_PLAINTEXT: 'FILESTATION_VAULT_VALIDATOR_V1',
    CHUNK_SIZE: 5 * 1024 * 1024,
    SINGLE_FILE_LIMIT: 5 * 1024 * 1024,

    toBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary);
    },

    fromBase64(base64) {
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes;
    },

    generateUUID() {
        return crypto.randomUUID ? crypto.randomUUID() :
            'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                const r = (crypto.getRandomValues(new Uint8Array(1))[0] & 0x0f);
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
    },

    generateSalt() {
        return crypto.getRandomValues(new Uint8Array(this.SALT_LENGTH));
    },

    async deriveKey(password, salt) {
        const encoder = new TextEncoder();
        const keyMaterial = await crypto.subtle.importKey(
            'raw', encoder.encode(password), 'PBKDF2', false, ['deriveKey']
        );
        return crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt, iterations: this.PBKDF2_ITERATIONS, hash: 'SHA-256' },
            keyMaterial,
            { name: 'AES-GCM', length: this.KEY_LENGTH },
            true,
            ['encrypt', 'decrypt']
        );
    },

    async encrypt(key, data) {
        const iv = crypto.getRandomValues(new Uint8Array(this.IV_LENGTH));
        const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, data);
        const result = new Uint8Array(iv.length + encrypted.byteLength);
        result.set(iv, 0);
        result.set(new Uint8Array(encrypted), iv.length);
        return result.buffer;
    },

    async decrypt(key, encData) {
        const data = new Uint8Array(encData);
        if (data.length < this.IV_LENGTH + 16) {
            throw new Error('Encrypted data too short: ' + data.length + ' bytes');
        }
        const iv = data.slice(0, this.IV_LENGTH);
        const ciphertext = data.slice(this.IV_LENGTH);
        return crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);
    },

    async encryptMeta(key, meta) {
        const encrypted = await this.encrypt(key, new TextEncoder().encode(JSON.stringify(meta)));
        return this.toBase64(encrypted);
    },

    async decryptMeta(key, encMetaBase64) {
        const decrypted = await this.decrypt(key, this.fromBase64(encMetaBase64));
        return JSON.parse(new TextDecoder().decode(decrypted));
    },

    async createValidator(key) {
        const encrypted = await this.encrypt(key, new TextEncoder().encode(this.VALIDATOR_PLAINTEXT));
        return this.toBase64(encrypted);
    },

    async verifyPassword(key, validatorBase64) {
        try {
            const decrypted = await this.decrypt(key, this.fromBase64(validatorBase64));
            return new TextDecoder().decode(decrypted) === this.VALIDATOR_PLAINTEXT;
        } catch (e) { return false; }
    },

    async createVault(password) {
        const salt = this.generateSalt();
        const key = await this.deriveKey(password, salt);
        const validator = await this.createValidator(key);
        return { salt: this.toBase64(salt), passwordValidator: validator, key };
    },

    async unlockVault(password, saltBase64, validatorBase64) {
        const salt = this.fromBase64(saltBase64);
        const key = await this.deriveKey(password, salt);
        const valid = await this.verifyPassword(key, validatorBase64);
        return { success: valid, key: valid ? key : null };
    },

    // ============================
    // 파일 암호화
    // ============================

    /**
     * 파일 암호화 — 자동 모드 선택
     * 작은 파일(<=5MB): 단일 모드 {encData, encMeta, uuid, chunked:false}
     * 대용량(>5MB): onChunkReady 콜백으로 청크 스트리밍
     * 
     * @param {CryptoKey} key
     * @param {File} file
     * @param {function} onProgress (percent) => {}
     * @param {function} onChunkReady (chunkIndex, encChunkArrayBuffer) => Promise — 청크 업로드 콜백
     * @returns {Promise<Object>}
     */
    async encryptFile(key, file, onProgress, onChunkReady) {
        const uuid = this.generateUUID();

        if (file.size <= this.SINGLE_FILE_LIMIT) {
            // 단일 모드 (하위 호환)
            const meta = {
                name: file.name, size: file.size,
                type: file.type || 'application/octet-stream',
                lastModified: file.lastModified, chunked: false
            };
            const encMeta = await this.encryptMeta(key, meta);
            if (onProgress) onProgress(5);
            const fileData = await file.arrayBuffer();
            if (onProgress) onProgress(30);
            const encData = await this.encrypt(key, fileData);
            if (onProgress) onProgress(90);
            return { encData, encMeta, uuid, chunked: false };
        }

        // 청크 모드
        const totalChunks = Math.ceil(file.size / this.CHUNK_SIZE);
        const meta = {
            name: file.name, size: file.size,
            type: file.type || 'application/octet-stream',
            lastModified: file.lastModified,
            chunked: true, totalChunks, chunkSize: this.CHUNK_SIZE
        };
        const encMeta = await this.encryptMeta(key, meta);

        for (let i = 0; i < totalChunks; i++) {
            const start = i * this.CHUNK_SIZE;
            const end = Math.min(start + this.CHUNK_SIZE, file.size);
            const chunkData = await file.slice(start, end).arrayBuffer();
            const encChunk = await this.encrypt(key, chunkData);

            if (onChunkReady) {
                await onChunkReady(i, encChunk);
            }

            if (onProgress) {
                onProgress(Math.round(((i + 1) / totalChunks) * 90));
            }
        }

        return { encMeta, uuid, chunked: true, totalChunks };
    },

    // ============================
    // 파일 복호화
    // ============================

    /** 단일 암호화 파일 복호화 → Blob (하위 호환) */
    async decryptFile(key, encData, mimeType) {
        const decrypted = await this.decrypt(key, encData);
        return new Blob([decrypted], { type: mimeType || 'application/octet-stream' });
    },

    /**
     * 청크별 복호화 → Blob
     * @param {CryptoKey} key
     * @param {function} getChunk (index) => Promise<ArrayBuffer>
     * @param {number} totalChunks
     * @param {string} mimeType
     * @param {function} onProgress (percent) => {}
     */
    async decryptFileChunked(key, getChunk, totalChunks, mimeType, onProgress) {
        const parts = [];
        for (let i = 0; i < totalChunks; i++) {
            const encChunk = await getChunk(i);
            const decChunk = await this.decrypt(key, encChunk);
            parts.push(decChunk);
            if (onProgress) onProgress(Math.round(((i + 1) / totalChunks) * 100));
        }
        return new Blob(parts, { type: mimeType || 'application/octet-stream' });
    },

    async decryptToURL(key, encData, mimeType) {
        const blob = await this.decryptFile(key, encData, mimeType);
        return URL.createObjectURL(blob);
    },

    async changePassword(newPassword) {
        return this.createVault(newPassword);
    },

    /** 파일 확장자로 MIME type 추론 */
    guessMimeType(filename) {
        const ext = (filename || '').split('.').pop().toLowerCase();
        const map = {
            // 문서
            pdf: 'application/pdf', txt: 'text/plain', md: 'text/markdown',
            html: 'text/html', htm: 'text/html', xml: 'text/xml', json: 'application/json',
            csv: 'text/csv', rtf: 'application/rtf',
            // 오피스
            doc: 'application/msword', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            xls: 'application/vnd.ms-excel', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ppt: 'application/vnd.ms-powerpoint', pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            hwp: 'application/x-hwp', hwpx: 'application/x-hwpx',
            // 이미지
            jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif',
            webp: 'image/webp', bmp: 'image/bmp', svg: 'image/svg+xml', ico: 'image/x-icon',
            // 동영상
            mp4: 'video/mp4', webm: 'video/webm', mkv: 'video/x-matroska', avi: 'video/x-msvideo',
            mov: 'video/quicktime', wmv: 'video/x-ms-wmv', flv: 'video/x-flv', m4v: 'video/x-m4v',
            // 오디오
            mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', flac: 'audio/flac',
            m4a: 'audio/mp4', aac: 'audio/aac', wma: 'audio/x-ms-wma', opus: 'audio/opus',
            // 코드
            js: 'text/javascript', css: 'text/css', php: 'text/x-php',
            py: 'text/x-python', java: 'text/x-java', c: 'text/x-c', cpp: 'text/x-c++',
            // 압축
            zip: 'application/zip', rar: 'application/x-rar-compressed',
            '7z': 'application/x-7z-compressed', gz: 'application/gzip', tar: 'application/x-tar'
        };
        return map[ext] || 'application/octet-stream';
    }
};

window.E2ECrypto = E2ECrypto;
