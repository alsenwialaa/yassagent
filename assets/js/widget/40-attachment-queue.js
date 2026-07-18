(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var util = Runtime.util;
    var Contract = Runtime.publicContract;
    var MAX_OUTPUT_DIMENSION = 1600;
    var DEFAULT_MAX_IMAGE_BYTES = Contract.runtime.imagePolicy.max_decoded_bytes;
    var MAX_SOURCE_BYTES = 8388608;
    var MAX_SOURCE_WIDTH = 4096;
    var MAX_SOURCE_HEIGHT = 4096;
    var MAX_SOURCE_PIXELS = 12582912;
    var MAX_HEADER_BYTES = 262144;

    function parseDataUrl(value) {
        var match = /^data:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+/]+={0,2})$/i.exec(String(value || ''));
        if (!match) {
            return null;
        }
        var payload = match[2];
        var padding = /==$/.test(payload) ? 2 : (/=$/.test(payload) ? 1 : 0);
        return {
            mime_type: match[1].toLowerCase(),
            payload: payload,
            bytes: Math.max(0, Math.floor(payload.length / 4) * 3 - padding)
        };
    }

    function uint16be(bytes, offset) {
        return (bytes[offset] * 256) + bytes[offset + 1];
    }

    function uint16le(bytes, offset) {
        return bytes[offset] + (bytes[offset + 1] * 256);
    }

    function uint24le(bytes, offset) {
        return bytes[offset] + (bytes[offset + 1] * 256) + (bytes[offset + 2] * 65536);
    }

    function uint32be(bytes, offset) {
        return (bytes[offset] * 16777216) + (bytes[offset + 1] * 65536)
            + (bytes[offset + 2] * 256) + bytes[offset + 3];
    }

    function uint32le(bytes, offset) {
        return bytes[offset] + (bytes[offset + 1] * 256)
            + (bytes[offset + 2] * 65536) + (bytes[offset + 3] * 16777216);
    }

    function ascii(bytes, offset, length) {
        var result = '';
        var index;
        for (index = 0; index < length; index += 1) {
            result += String.fromCharCode(bytes[offset + index]);
        }
        return result;
    }

    function dimensions(mimeType, width, height) {
        width = Number(width);
        height = Number(height);
        if (!isFinite(width) || !isFinite(height)
            || Math.floor(width) !== width || Math.floor(height) !== height
            || width < 1 || height < 1
        ) {
            return null;
        }
        return { mime_type: mimeType, width: width, height: height };
    }

    function parsePngDimensions(bytes) {
        if (bytes.length < 24
            || bytes[0] !== 0x89 || bytes[1] !== 0x50 || bytes[2] !== 0x4e || bytes[3] !== 0x47
            || bytes[4] !== 0x0d || bytes[5] !== 0x0a || bytes[6] !== 0x1a || bytes[7] !== 0x0a
            || uint32be(bytes, 8) !== 13 || ascii(bytes, 12, 4) !== 'IHDR'
        ) {
            return null;
        }
        return dimensions('image/png', uint32be(bytes, 16), uint32be(bytes, 20));
    }

    function isJpegStartOfFrame(marker) {
        return (marker >= 0xc0 && marker <= 0xc3)
            || (marker >= 0xc5 && marker <= 0xc7)
            || (marker >= 0xc9 && marker <= 0xcb)
            || (marker >= 0xcd && marker <= 0xcf);
    }

    function parseJpegDimensions(bytes) {
        if (bytes.length < 11 || bytes[0] !== 0xff || bytes[1] !== 0xd8) {
            return null;
        }
        var offset = 2;
        while (offset + 1 < bytes.length) {
            while (offset < bytes.length && bytes[offset] === 0xff) {
                offset += 1;
            }
            if (offset >= bytes.length) {
                break;
            }
            var marker = bytes[offset];
            offset += 1;
            if (marker === 0xd9 || marker === 0xda) {
                break;
            }
            if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
                continue;
            }
            if (offset + 1 >= bytes.length) {
                break;
            }
            var length = uint16be(bytes, offset);
            if (length < 2 || offset + length > bytes.length) {
                break;
            }
            if (isJpegStartOfFrame(marker) && length >= 7) {
                return dimensions('image/jpeg', uint16be(bytes, offset + 5), uint16be(bytes, offset + 3));
            }
            offset += length;
        }
        return null;
    }

    function parseWebpDimensions(bytes) {
        if (bytes.length < 30 || ascii(bytes, 0, 4) !== 'RIFF' || ascii(bytes, 8, 4) !== 'WEBP') {
            return null;
        }
        var offset = 12;
        while (offset + 8 <= bytes.length) {
            var type = ascii(bytes, offset, 4);
            var length = uint32le(bytes, offset + 4);
            var dataOffset = offset + 8;
            if (length < 0 || dataOffset + Math.min(length, 10) > bytes.length) {
                break;
            }
            if (type === 'VP8X' && length >= 10 && dataOffset + 10 <= bytes.length) {
                return dimensions(
                    'image/webp',
                    uint24le(bytes, dataOffset + 4) + 1,
                    uint24le(bytes, dataOffset + 7) + 1
                );
            }
            if (type === 'VP8 ' && length >= 10 && dataOffset + 10 <= bytes.length
                && bytes[dataOffset + 3] === 0x9d
                && bytes[dataOffset + 4] === 0x01
                && bytes[dataOffset + 5] === 0x2a
            ) {
                return dimensions(
                    'image/webp',
                    uint16le(bytes, dataOffset + 6) & 0x3fff,
                    uint16le(bytes, dataOffset + 8) & 0x3fff
                );
            }
            if (type === 'VP8L' && length >= 5 && dataOffset + 5 <= bytes.length
                && bytes[dataOffset] === 0x2f
            ) {
                return dimensions(
                    'image/webp',
                    1 + bytes[dataOffset + 1] + ((bytes[dataOffset + 2] & 0x3f) * 256),
                    1 + ((bytes[dataOffset + 2] & 0xc0) >> 6)
                        + (bytes[dataOffset + 3] * 4)
                        + ((bytes[dataOffset + 4] & 0x0f) * 1024)
                );
            }
            var next = dataOffset + length + (length % 2);
            if (next <= offset || next > bytes.length) {
                break;
            }
            offset = next;
        }
        return null;
    }

    function parseSourceDimensions(bytes, declaredMimeType) {
        var result = null;
        if (declaredMimeType === 'image/png') {
            result = parsePngDimensions(bytes);
        } else if (declaredMimeType === 'image/jpeg') {
            result = parseJpegDimensions(bytes);
        } else if (declaredMimeType === 'image/webp') {
            result = parseWebpDimensions(bytes);
        }
        return result && result.mime_type === declaredMimeType ? result : null;
    }

    function sourceDimensionsAllowed(value, policy) {
        return Boolean(value
            && value.width <= policy.maxSourceWidth
            && value.height <= policy.maxSourceHeight
            && (value.width * value.height) <= policy.maxSourcePixels);
    }

    function readSourceDimensions(file, maximumHeaderBytes, callback) {
        if (!file || typeof file.slice !== 'function' || typeof FileReader !== 'function') {
            callback(null);
            return function () {};
        }
        var reader = new FileReader();
        var completed = false;
        function finish(value) {
            if (!completed) {
                completed = true;
                callback(value);
            }
        }
        reader.onload = function () {
            try {
                var bytes = new Uint8Array(reader.result);
                finish(parseSourceDimensions(bytes, String(file.type || '').toLowerCase()));
            } catch (error) {
                finish(null);
            }
        };
        reader.onerror = function () { finish(null); };
        try {
            var size = Math.min(Math.max(0, Number(file.size || 0)), maximumHeaderBytes);
            reader.readAsArrayBuffer(file.slice(0, size));
        } catch (error) {
            finish(null);
        }
        return function () {
            if (completed) {
                return;
            }
            completed = true;
            reader.onload = null;
            reader.onerror = null;
            if (typeof reader.abort === 'function') {
                try { reader.abort(); } catch (error) {}
            }
        };
    }

    function encodeCanvas(canvas, maximumBytes) {
        var formats = ['image/webp', 'image/jpeg'];
        var qualities = [0.82, 0.72, 0.62, 0.52, 0.42];
        var formatIndex;
        var qualityIndex;
        var candidate;
        var parsed;
        for (formatIndex = 0; formatIndex < formats.length; formatIndex += 1) {
            for (qualityIndex = 0; qualityIndex < qualities.length; qualityIndex += 1) {
                candidate = canvas.toDataURL(formats[formatIndex], qualities[qualityIndex]);
                parsed = parseDataUrl(candidate);
                if (parsed && parsed.bytes <= maximumBytes) {
                    return { dataUrl: candidate, mime_type: parsed.mime_type };
                }
            }
        }
        return null;
    }

    function targetDimensions(sourceWidth, sourceHeight) {
        var scale = Math.min(1, MAX_OUTPUT_DIMENSION / Math.max(sourceWidth, sourceHeight));
        return {
            width: Math.max(1, Math.round(sourceWidth * scale)),
            height: Math.max(1, Math.round(sourceHeight * scale))
        };
    }

    function releaseCanvas(canvas, context) {
        try {
            if (context && typeof context.clearRect === 'function') {
                context.clearRect(0, 0, canvas.width, canvas.height);
            }
            canvas.width = 1;
            canvas.height = 1;
        } catch (error) {
            // Resource release is best effort after the result is already known.
        }
    }

    function renderDecodedImage(image, sourceWidth, sourceHeight, maximumBytes) {
        var target = targetDimensions(sourceWidth, sourceHeight);
        var width = target.width;
        var height = target.height;
        var attempt;
        for (attempt = 0; attempt < 5; attempt += 1) {
            var canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            var context = canvas.getContext('2d');
            if (!context) {
                releaseCanvas(canvas, context);
                return null;
            }
            try {
                context.drawImage(image, 0, 0, width, height);
                var encoded = encodeCanvas(canvas, maximumBytes);
                releaseCanvas(canvas, context);
                if (encoded) {
                    return encoded;
                }
            } catch (error) {
                releaseCanvas(canvas, context);
                return null;
            }
            if (Math.max(width, height) <= 480) {
                break;
            }
            width = Math.max(1, Math.round(width * 0.75));
            height = Math.max(1, Math.round(height * 0.75));
        }
        return null;
    }

    function readFileAsDataUrl(file, callback) {
        var reader = new FileReader();
        var completed = false;
        function finish(value) {
            if (!completed) {
                completed = true;
                callback(value);
            }
        }
        reader.onload = function () {
            finish(typeof reader.result === 'string' ? reader.result : '');
        };
        reader.onerror = function () { finish(''); };
        try {
            reader.readAsDataURL(file);
        } catch (error) {
            finish('');
        }
        return function () {
            if (completed) {
                return;
            }
            completed = true;
            reader.onload = null;
            reader.onerror = null;
            if (typeof reader.abort === 'function') {
                try { reader.abort(); } catch (error) {}
            }
        };
    }

    function prepareWithImageElement(file, inspected, policy, maximumBytes, callback, isCancelled) {
        var cancelled = typeof isCancelled === 'function' ? isCancelled : function () { return false; };
        if (typeof window.Image !== 'function') {
            callback(null);
            return function () {};
        }

        var urlApi = window.URL || (typeof URL !== 'undefined' ? URL : null);
        var objectUrl = '';
        var image = new window.Image();
        var completed = false;
        function cleanup() {
            image.onload = null;
            image.onerror = null;
            try { image.src = ''; } catch (error) {}
            if (objectUrl && urlApi && typeof urlApi.revokeObjectURL === 'function') {
                try { urlApi.revokeObjectURL(objectUrl); } catch (error) {}
            }
            objectUrl = '';
        }
        function finish(value) {
            if (!completed && !cancelled()) {
                completed = true;
                cleanup();
                callback(value);
            } else if (!completed) {
                completed = true;
                cleanup();
                callback(null);
            }
        }
        image.onload = function () {
            try {
                var actual = dimensions(
                    inspected.mime_type,
                    Number(image.naturalWidth || image.width || 0),
                    Number(image.naturalHeight || image.height || 0)
                );
                if (!sourceDimensionsAllowed(actual, policy)) {
                    finish(null);
                    return;
                }
                finish(renderDecodedImage(image, actual.width, actual.height, maximumBytes));
            } catch (error) {
                finish(null);
            }
        };
        image.onerror = function () { finish(null); };

        if (urlApi && typeof urlApi.createObjectURL === 'function') {
            try {
                objectUrl = urlApi.createObjectURL(file);
                image.src = objectUrl;
                return function () {
                    if (!completed) {
                        completed = true;
                        cleanup();
                        callback(null);
                    }
                };
            } catch (error) {
                objectUrl = '';
            }
        }
        var readCancel = readFileAsDataUrl(file, function (source) {
            if (!source) {
                finish(null);
                return;
            }
            image.src = source;
        });
        return function () {
            readCancel();
            if (!completed) {
                completed = true;
                cleanup();
                callback(null);
            }
        };
    }

    function prepareImage(file, inspected, policy, maximumBytes, callback, isCancelled) {
        var cancelled = typeof isCancelled === 'function' ? isCancelled : function () { return false; };
        if (typeof window.createImageBitmap !== 'function') {
            return prepareWithImageElement(file, inspected, policy, maximumBytes, callback, cancelled);
        }

        var target = targetDimensions(inspected.width, inspected.height);
        var promise;
        try {
            promise = window.createImageBitmap(file, {
                resizeWidth: target.width,
                resizeHeight: target.height,
                resizeQuality: 'high'
            });
        } catch (error) {
            return prepareWithImageElement(file, inspected, policy, maximumBytes, callback, cancelled);
        }
        if (!promise || typeof promise.then !== 'function') {
            return prepareWithImageElement(file, inspected, policy, maximumBytes, callback, cancelled);
        }
        var stopped = false;
        var fallbackCancel = null;
        promise.then(function (bitmap) {
            var value = null;
            if (!stopped && !cancelled()) {
                try {
                    value = renderDecodedImage(bitmap, inspected.width, inspected.height, maximumBytes);
                } catch (error) {
                    value = null;
                }
            }
            if (bitmap && typeof bitmap.close === 'function') {
                try { bitmap.close(); } catch (error) {}
            }
            callback(!stopped && !cancelled() ? value : null);
        }, function () {
            if (!stopped && !cancelled()) {
                fallbackCancel = prepareWithImageElement(
                    file, inspected, policy, maximumBytes, callback, cancelled
                );
            } else {
                callback(null);
            }
        });
        return function () {
            stopped = true;
            if (typeof fallbackCancel === 'function') {
                fallbackCancel();
            }
        };
    }

    function AttachmentQueue(config, onChange, onNotice) {
        this.maxImages = util.boundedInteger(config.maxImages, Contract.limits.attachmentMaxItems, 0, Contract.limits.attachmentMaxItems);
        this.maxBytes = util.boundedInteger(config.maxImageBytes, DEFAULT_MAX_IMAGE_BYTES, 1, DEFAULT_MAX_IMAGE_BYTES);
        this.maxSourceBytes = util.boundedInteger(config.maxSourceImageBytes, MAX_SOURCE_BYTES, this.maxBytes, MAX_SOURCE_BYTES);
        this.maxSourceHeaderBytes = util.boundedInteger(config.maxSourceImageHeaderBytes, MAX_HEADER_BYTES, 30, MAX_HEADER_BYTES);
        this.maxSourceWidth = util.boundedInteger(config.maxSourceImageWidth, MAX_SOURCE_WIDTH, MAX_OUTPUT_DIMENSION, MAX_SOURCE_WIDTH);
        this.maxSourceHeight = util.boundedInteger(config.maxSourceImageHeight, MAX_SOURCE_HEIGHT, MAX_OUTPUT_DIMENSION, MAX_SOURCE_HEIGHT);
        this.maxSourcePixels = util.boundedInteger(config.maxSourceImagePixels, MAX_SOURCE_PIXELS, 1, MAX_SOURCE_PIXELS);
        this.entries = [];
        this.decodeQueue = [];
        this.activeDecodes = 0;
        this.maxConcurrentDecodes = 1;
        this.onChange = onChange;
        this.onNotice = onNotice;
    }

    AttachmentQueue.prototype.setLimits = function (maximum, maximumBytes) {
        this.maxImages = util.boundedInteger(maximum, this.maxImages, 0, Contract.limits.attachmentMaxItems);
        this.maxBytes = util.boundedInteger(maximumBytes, this.maxBytes, 0, DEFAULT_MAX_IMAGE_BYTES);
        if (this.maxImages === 0 || this.maxBytes === 0) {
            this.entries.forEach(this.cancelEntry, this);
            this.entries = [];
        } else if (this.entries.length > this.maxImages) {
            this.entries.slice(this.maxImages).forEach(this.cancelEntry, this);
            this.entries = this.entries.slice(0, this.maxImages);
        }
        this.emit();
    };

    AttachmentQueue.prototype.sourcePolicy = function () {
        return {
            maxSourceWidth: this.maxSourceWidth,
            maxSourceHeight: this.maxSourceHeight,
            maxSourcePixels: this.maxSourcePixels
        };
    };

    AttachmentQueue.prototype.select = function (files) {
        var self = this;
        var candidates = Array.isArray(files) ? files : [];
        var remaining = Math.max(0, this.maxImages - this.entries.length);
        var limitNotified = false;

        candidates.forEach(function (file) {
            if (remaining <= 0 || self.maxBytes <= 0) {
                if (!limitNotified) {
                    self.notice(util.text('imageLimit', 'لا يمكنك إرفاق صور إضافية.'));
                    limitNotified = true;
                }
                return;
            }
            if (!file || Contract.enums.imageMimeTypes.indexOf(file.type) === -1) {
                self.notice(util.text('unsupportedImage', 'تُقبل صور JPEG وPNG وWebP فقط.'));
                return;
            }
            if (Number(file.size || 0) > self.maxSourceBytes) {
                self.notice(util.text('imageTooLarge', 'إحدى الصور المحددة كبيرة جداً.'));
                return;
            }

            var entry = {
                id: util.randomId(),
                name: String(file.name || util.text('imageAttachment', 'صورة مرفقة')),
                mime_type: String(file.type),
                data: '',
                status: 'reading',
                cancelled: false,
                cancellers: []
            };
            self.entries.push(entry);
            remaining -= 1;
            self.emit();

            var headerCancel = readSourceDimensions(file, self.maxSourceHeaderBytes, function (inspected) {
                var current = self.find(entry.id);
                if (!current || current.cancelled) {
                    return;
                }
                if (!inspected) {
                    self.removeFailed(entry.id, 'unsupportedImage', 'تعذر التحقق من بنية الصورة.');
                    return;
                }
                if (!sourceDimensionsAllowed(inspected, self.sourcePolicy())) {
                    self.removeFailed(
                        entry.id,
                        'imageDimensionsTooLarge',
                        'أبعاد الصورة كبيرة جداً. اختر صورة لا تتجاوز 4096 بكسل لأي ضلع و12 ميجابكسل إجمالاً.'
                    );
                    return;
                }
                self.enqueueDecode(entry, file, inspected);
            });
            entry.cancellers.push(headerCancel);
        });
    };

    AttachmentQueue.prototype.enqueueDecode = function (entry, file, inspected) {
        var job = {
            entry: entry,
            file: file,
            inspected: inspected,
            started: false,
            cancelled: false,
            cancelWork: null
        };
        entry.decodeJob = job;
        this.decodeQueue.push(job);
        this.drainDecodes();
    };

    AttachmentQueue.prototype.drainDecodes = function () {
        var self = this;
        while (this.activeDecodes < this.maxConcurrentDecodes && this.decodeQueue.length > 0) {
            var job = this.decodeQueue.shift();
            if (!job || job.cancelled || job.entry.cancelled || !this.find(job.entry.id)) {
                continue;
            }
            job.started = true;
            this.activeDecodes += 1;
            job.cancelWork = prepareImage(
                job.file,
                job.inspected,
                this.sourcePolicy(),
                this.maxBytes,
                function (prepared) {
                    self.finishDecode(job, prepared);
                },
                function () { return job.cancelled || job.entry.cancelled; }
            );
        }
    };

    AttachmentQueue.prototype.finishDecode = function (job, prepared) {
        if (!job || job.finished) {
            return;
        }
        job.finished = true;
        this.activeDecodes = Math.max(0, this.activeDecodes - 1);
        var ready = this.find(job.entry.id);
        if (ready && !ready.cancelled && !job.cancelled) {
            if (!prepared) {
                this.removeFailed(ready.id, 'imageTooLarge', 'تعذر تصغير الصورة إلى حجم رفع آمن.');
            } else {
                ready.data = prepared.dataUrl;
                ready.mime_type = prepared.mime_type;
                ready.status = 'ready';
                ready.decodeJob = null;
                this.emit();
            }
        }
        this.drainDecodes();
    };

    AttachmentQueue.prototype.cancelEntry = function (entry) {
        if (!entry || entry.cancelled) {
            return;
        }
        entry.cancelled = true;
        if (entry.decodeJob) {
            entry.decodeJob.cancelled = true;
            if (entry.decodeJob.started && typeof entry.decodeJob.cancelWork === 'function') {
                try { entry.decodeJob.cancelWork(); } catch (error) {}
            }
        }
        (Array.isArray(entry.cancellers) ? entry.cancellers : []).forEach(function (cancel) {
            if (typeof cancel === 'function') {
                try { cancel(); } catch (error) {}
            }
        });
        entry.cancellers = [];
        entry.data = '';
    };

    AttachmentQueue.prototype.removeFailed = function (id, key, fallback) {
        var failed = this.find(id);
        this.cancelEntry(failed);
        this.entries = this.entries.filter(function (item) { return item.id !== id; });
        this.notice(util.text(key, fallback));
        this.emit();
    };

    AttachmentQueue.prototype.find = function (id) {
        var result = null;
        this.entries.some(function (entry) {
            if (entry.id === id) {
                result = entry;
                return true;
            }
            return false;
        });
        return result;
    };

    AttachmentQueue.prototype.remove = function (id) {
        this.cancelEntry(this.find(id));
        this.entries = this.entries.filter(function (entry) { return entry.id !== id; });
        this.emit();
    };

    AttachmentQueue.prototype.hasPending = function () {
        return this.entries.some(function (entry) { return entry.status === 'reading'; });
    };

    AttachmentQueue.prototype.readyPayloads = function () {
        return this.entries.filter(function (entry) {
            return entry.status === 'ready' && entry.data;
        }).map(function (entry) {
            var parsed = parseDataUrl(entry.data);
            return parsed ? { mime_type: entry.mime_type, data: parsed.payload } : null;
        }).filter(function (entry) { return entry !== null; });
    };

    AttachmentQueue.prototype.readyPreviews = function () {
        return this.entries.filter(function (entry) {
            return entry.status === 'ready' && entry.data;
        }).map(function (entry) {
            return {
                src: entry.data,
                alt: entry.name || util.text('imageAttachment', 'صورة مرفقة')
            };
        });
    };

    AttachmentQueue.prototype.clear = function () {
        this.entries.forEach(this.cancelEntry, this);
        this.entries = [];
        this.emit();
    };

    AttachmentQueue.prototype.publicEntries = function () {
        return this.entries.map(function (entry) {
            return {
                id: entry.id,
                name: entry.name,
                data: entry.data,
                status: entry.status
            };
        });
    };

    AttachmentQueue.prototype.emit = function () {
        if (typeof this.onChange === 'function') {
            this.onChange(this.publicEntries());
        }
    };

    AttachmentQueue.prototype.notice = function (message) {
        if (typeof this.onNotice === 'function') {
            this.onNotice(message);
        }
    };

    Runtime.AttachmentQueue = AttachmentQueue;
}(window));
