# خطة تنفيذ: الرفع المباشر إلى Cloudflare R2

> ملف تتبّع. كل مرحلة فيها مهام ومعيار قبول. علّم `[x]` أول ما المعيار يتحقق فعلياً.

---

## السياق (اقرأه قبل أي كود)

الهدف: الفيديو يترفع من المتصفح لـ R2 مباشرةً، من غير ما الملف يعدّي على PHP خالص.

### الوضع الحالي

```
متصفح ──1.5 جيجا──► PHP ──► قرص محلي ──► thumb + duration (ffmpeg محلي)
                                           │
                                           └──1.5 جيجا──► R2/temp-videos ──► سيرفر الترميز
```

سيرفر الترميز بيسحب الأصل من R2، يعمل HLS، يرجّعه مكانه، وينادي `uploadFromServer` على السيرفر الرئيسي.

### الوضع المطلوب

```
متصفح ══2 جيجا══════════════════════════════► R2/temp-videos ──► سيرفر الترميز
   │                                              ▲
   └── init / sign-part / complete ──► PHP ───────┘
        (كيلوبايتات بس)
```

### خمس حقائق تغيّر التنفيذ

1. **الـ chunking الحالي وهمي.** في `hls-video-maneger.blade.php` فيه `parallel: true` و `chunkSize` على مُنشئ Uppy و `parallelUploads` على XHRUpload. **التلاتة مش خيارات موجودة في Uppy** وبيتجاهلوا بصمت، فالملف كله بيتبعت في POST واحد. وعلى السيرفر `HandlerFactory::classFromRequest()` مابيلاقيش chunk headers فبيرجع `SingleUploadHandler` — يعني `pion/laravel-chunk-upload` مركّب ومش شغال. ده سبب `upload_max_filesize = 2G` و `max_execution_time = 3600`.

2. **مسار R2 الحقيقي** (من `UploadToStepsLocalencoderService.php:37`):
   ```
   temp-videos/{tenant->media_folder}/{video_uuid}/vd.{ext}
   ```
   مش `videos/{id}/original.mp4`. بادئة المستأجر والـ UUID موجودين أصلاً، فمفيش خطر تصادم بين المستأجرين. **الرفع المباشر لازم يكتب في نفس المسار بالظبط** عشان سيرفر الترميز وكل اللي بعده يفضل شغال من غير تعديل.

3. **الـ `created` hook في `HlsVideo` فخ.** بينادي `createThumb` و `getVideoDuration` (الاتنين بيقروا من القرص المحلي) و `handleVideoQualities` (بيبدأ الترميز). في التصميم الجديد الصف بيتعمل **قبل** الرفع عشان الـ UUID يبقى موجود وقت بناء الـ key — يعني الـ hook هيدفع فيديو فاضي للترميز. ده أخطر جزء في المشروع.

4. **الباكدج مستخدمة في مشاريع تانية.** كل حاجة خلف `upload_driver`، الافتراضي `server`، والمسار القديم ما يتغيّرش سلوكه ولا حرف.

5. **القيود التقنية**: Laravel 8 (`Kernel.php` مش `bootstrap/app.php`، و `->change()` محتاج doctrine/dbal اللي مابيعرفش يقرا ENUM)، و Uppy 3.18.0 من CDN (الـ plugin اسمه `AwsS3Multipart` منفصل، مش `@uppy/aws-s3` الموحّد بتاع v4+).

### قواعد ثابتة

| # | القاعدة | ليه |
|---|---|---|
| 1 | المسار القديم (`upload_driver = server`) ما يتلمسش | الباكدج في مشاريع تانية |
| 2 | الـ key يتبني على السيرفر من صف الفيديو، **مطلقاً** مش من الـ request | العميل ميقدرش يوجّه الكتابة لأي مسار |
| 3 | العميل بيبعت `video_id` و `partNumber` وبس | الـ uploadId والـ key بيتقروا من الـ DB |
| 4 | التوقيع مسموح بس والحالة `pending_upload` | منع الكتابة فوق فيديو منشور |
| 5 | `/complete` لازم يكون idempotent | هيتنادى مرتين (retry، سباق مع أمر المصالحة) |
| 6 | حجم الجزء **ثابت** | R2 بيفرض تساوي كل الأجزاء ماعدا الأخير |

---

## المرحلة 0 — فحوصات (قبل أي كود)

- [ ] **موقع بكت R2 الفعلي**: `npx wrangler r2 bucket info <BUCKET>`. البكت متعلم `auto` وده ساعات بيقع في أمريكا الشمالية. لو طلع ENAM/WNAM → ميزة Local Uploads هتفرق كتير مع طلاب مصر (المرحلة 9).
- [ ] **اختبار wildcard في CORS**: جرّب `https://*.example.com` في `AllowedOrigins` بملف صغير. توثيق R2 بيقول "مطابقة تامة" ومابيذكرش wildcard.
      - لو اشتغل → خلصنا.
      - لو مااشتغلش → قرار معماري: إما أتمتة إضافة origin لكل مستأجر جديد، أو تحميل واجهة الرفع في `iframe` من دومين واحد ثابت. **ده بيغيّر شكل الواجهة، فلازم يتحسم دلوقتي.**
- [ ] **نسخة `aws/aws-sdk-php`**: `composer show aws/aws-sdk-php`. النسخ الحديثة بتضيف `x-amz-checksum-*` افتراضياً وبتكسّر الروابط الموقّعة لأن المتصفح مابيبعتش الهيدر. العلاج: `request_checksum_calculation => 'when_required'`.
- [ ] **إعداد disk الـ `r2`** في `config/filesystems.php` موجود وفيه `endpoint` و `bucket` و `use_path_style_endpoint`.

**معيار القبول**: عارف منطقة البكت، وعارف إذا كان الـ wildcard شغال ولا لأ، وعارف نسخة الـ SDK.

---

## المرحلة 1 — فصل دورة حياة الفيديو ⚠️ البوابة

من غير المرحلة دي، أي endpoint هنكتبه هيدفع فيديوهات فاضية للترميز.

- [x] `config/hls-videos.php`: إضافة `upload_driver`, `uploaded_videos_disk`, `temp_videos_prefix`, وبلوك `direct_upload`. **وكمان** `uploader_access_url` و `local_server_password` — الاتنين مستخدمين في الكود ومش معرّفين في الـ config.
- [x] Migration:
      - `status` من `ENUM` لـ `VARCHAR(20)` بـ `DB::statement` خام (مش `->change()`: doctrine/dbal مابيعرفش يقرا ENUM). الافتراضي يفضل `'uploaded'`.
      - أعمدة: `r2_key`, `r2_upload_id`, `upload_size`, `upload_started_at`.
      - index على `(status, upload_started_at)` للأمر بتاع المصالحة.
- [x] `HlsVideo`: ثابتين `PENDING_UPLOAD` و `UPLOAD_FAILED`، cast لـ `upload_started_at`, scope `pendingUpload`, accessor `original_key`.
- [x] **حراسة الـ `created` hook**: لو الحالة `PENDING_UPLOAD` → نادِ `protectVideo` بس وارجع. المسار القديم يمر من نفس الفرع بنفس الترتيب الأصلي بالظبط.
- [x] `VideoService`: `originalKey()`, `tenantOriginalPrefix()`, `startProcessing()` (idempotent), `createPendingVideo()`.
- [x] إصلاح الـ `deleting` hook: الأصل تحت بادئة `temp-videos/` ومش بيتمسح حالياً — تسريب تخزين موجود من قبل المشروع ده.

**معيار القبول**: `HlsVideo::create(['status' => 'pending_upload', ...])` مابيعملش أي صف في `hls_video_qualities` ومابيناديش ffmpeg. والمسار القديم لسه بيعمل صورة ومدة وبيبدأ الترميز زي ما هو.

---

## المرحلة 2 — خدمة التوقيع

- [ ] `DirectUploadService`: `createMultipartUpload`, `signPart`, `listParts`, `completeMultipartUpload`, `abortMultipartUpload`, `headObject`.
- [ ] بناء `S3Client` **من إعداد الـ disk مباشرة**، مش من Flysystem adapter — طريقة الوصول للـ adapter مختلفة بين Laravel 8 (Flysystem 1) و Laravel 9+ (Flysystem 3) والباكدج بتدعم الاتنين.
- [ ] `try/catch` حوالين `request_checksum_calculation` (النسخ القديمة من الـ SDK بترفض الخيار).
- [ ] في `signPart`: **ما تحطش** `Body` ولا `ContentLength` — لو اتحطوا هيدخلوا في التوقيع والمتصفح بيبعت بتوعه.

**معيار القبول**: `tinker` → توقيع جزء → `curl -X PUT --upload-file` عليه → 200 ومعاه ETag.

---

## المرحلة 3 — الـ endpoints

- [ ] `DirectUploadController`: `init`, `signPart`, `parts`, `complete`, `abort`.
- [ ] `InitDirectUploadRequest` مع `safeExtension()` (الامتداد جزء من مسار تخزين — يتنضّف).
- [ ] الراوتس تحت `hls/videos/direct` **جوه** `uploader_access_middleware`. لاحظ إن راوت `upload` القديم بره المجموعة دي و `authorize()` بيرجّع `true` — ثغرة قائمة، ومتكرّرهاش في راوتس التوقيع لأنها بتدي حق كتابة على البكت.
- [ ] `throttle` مخصص (600/دقيقة). الافتراضي 60 **هيكسر الرفع**: ملف 2 جيجا بأجزاء 32 ميجا = 64 نداء توقيع بسرعة.
- [ ] الخدمة تتحل lazily مش injection في الـ constructor — الـ constructor بيرمي استثناء لو الـ disk مش S3، والراوتس مسجّلة حتى على التثبيتات اللي بالمسار القديم (لازم 404 مش 500).
- [ ] `assertOwnedKey()`: الـ key لازم يبدأ ببادئة المستأجر الحالي. حزام وحمالة فوق عزل قواعد البيانات.

**معيار القبول**: `init` بيرجّع `uploadId`، و `sign-part` بـ `partNumber = 0` أو `10001` بيرجّع 422، و `sign-part` على فيديو حالته `uploaded` بيرجّع 409.

---

## المرحلة 4 — إعداد البكت

- [ ] CORS: `ExposeHeaders: ["ETag"]` **إجباري** — من غيره Uppy مش هيقدر يقرا الـ ETag من رد كل جزء ومش هيكمّل.
- [ ] lifecycle: `AbortIncompleteMultipartUpload` بعد يوم (الافتراضي 7 أيام).
- [ ] انتشار سياسة CORS بياخد لحد 30 ثانية — ما تستعجلش وتفتكرها مكسورة.

**معيار القبول**: PUT من `fetch()` في console المتصفح على رابط موقّع بيرجّع ETag مقروء.

---

## المرحلة 5 — Uppy

- [ ] شيل `parallel` و `chunkSize` و `parallelUploads` (مش خيارات حقيقية).
- [ ] فرع `AwsS3Multipart` خلف `HLS_UPLOAD.driver === 'direct'`.
- [ ] `getChunkSize` يرجّع **ثابت** 32 MiB.
- [ ] `limit: 4` أجزاء متوازية.
- [ ] استئناف: خزّن `{videoId, uploadId, key}` في `localStorage` بتوقيع `name:size:lastModified`. TTL 24 ساعة يطابق نافذة الـ abort.
- [ ] قبل ما تثق في رفعة مخزّنة، نادِ `listParts` عليها — ممكن تكون اتلغت من أمر التنظيف أو من الـ lifecycle.
- [ ] `upload-success` يتعامل مع الحالتين (في الوضع المباشر مفيش `response.body` من PHP).

**معيار القبول**: ملف 2 جيجا يرفع، اقطع الشبكة في النص وارجّعها → يكمّل من مكانه. اقفل التاب وافتحه → يستأنف.

---

## المرحلة 6 — تبسيط `UploadToStepsLocalencoderService`

- [ ] الرفع من السيرفر لـ R2 يبقى مشروط بوجود الملف محلياً.
- [ ] لو مش محلي ومش على R2 → استثناء واضح بالمسارين.
- [ ] استخدم `VideoService::originalKey()` بدل بناء السلسلة يدوياً.

**معيار القبول**: فيديو مرفوع بالمسار الجديد يوصل لسيرفر الترميز من غير ما PHP ينقل بايت واحد. وفيديو بالمسار القديم يشتغل زي ما هو.

---

## المرحلة 7 — الصورة والمدة (محتاج الريبو التاني)

الملف مابقاش بيعدّي على PHP، فـ `createThumb` و `getVideoDuration` مابقاش ليهم مصدر.

- [ ] `HlsVideoController::probeMetadata` + راوت، محمي بـ `steps_encoder_token`.
- [ ] `VideoService::applyProbedMetadata` — يكتب الصورة على **نفس** القرص والمسار القديم (`{media_folder}/{video_id}/thumb.jpg`) ويضبط `stream_data.thumb_disk`، عشان `getThumbUrlAttribute` يشتغل من غير تعديل.
- [ ] ⚠️ **تعديل في سيرفر الترميز** (ريبو تاني): بعد ما ينزّل الأصل وقبل ما يبدأ الترميز، يبعت:
  ```http
  POST {main}/hls/videos/probe-metadata/{videoId}
  Authorization: {steps_encoder_token}
  { "tenant_id": 12, "duration": 3721.5, "width": 1920, "height": 1080, "thumb": "<base64 JPEG>" }
  ```

**معيار القبول**: فيديو مرفوع بالمسار الجديد يظهر بصورة ومدة صح. **من غير تعديل سيرفر الترميز المعيار ده مستحيل يتحقق.**

---

## المرحلة 8 — شبكة الأمان

- [ ] أمر `hls-videos:reconcile-uploads` بيلف على كل المستأجرين:
      - **استرجاع**: `HeadObject` لقى الملف → كمّل الحالة وابدأ الترميز. (الحالة: `/complete` نجح على R2 والرد ما وصلش — تاب اتقفل، نت قطع، deploy في النص.)
      - **تنظيف**: رفعة مهجورة → `AbortMultipartUpload` + `upload_failed`.
- [ ] `--dry-run` و `--tenant=` للتشخيص.
- [ ] جدولة كل 10 دقايق.

**معيار القبول**: اقفل التاب بعد ما الرفع يخلص وقبل ما `/complete` يرد → بعد ≤10 دقايق الفيديو يكمّل لوحده.

**ملحوظة**: R2 Event Notifications شغالة وبتغطي `CompleteMultipartUpload` عبر Cloudflare Queues، بس محتاجة Worker أو HTTP-pull consumer — مكوّن جديد في المعمارية. الأمر ده بيحل نفس المشكلة بـ PHP خالص. سيبها لما تحتاج أحداث حقيقية (رفع من خارج المتصفح).

---

## المرحلة 9 — Local Uploads (بعد الاستقرار)

ميزة R2 في open beta من فبراير 2026. مجانية، بتغطي `UploadPart` (متوافقة مع multipart)، الاتساق قوي، والإشعارات بتتنشر من منطقة البكت الأصلية. لحد 75% تقليل في TTLB للرفع من منطقة بعيدة.

- [ ] فعّل: `npx wrangler r2 bucket local-uploads enable <BUCKET>`
- [ ] قيس قبل وبعد.

⚠️ القراءة من منطقة البكت ممكن تبقى أبطأ مؤقتاً لحد ما النسخ غير المتزامن يخلص — وسيرفر الترميز بيسحب الأصل فوراً بعد الرفع، فده السيناريو المتأثر. مش مانع، بس يستحق قياس.

متغير مستقل، قابل للتراجع بأمر واحد. **آخر حاجة، مش قبل ما الباقي يستقر.**

---

## المرحلة 10 — الحصاد

- [ ] `upload_max_filesize`: `2G → 20M`
- [ ] `max_execution_time`: `3600 → 60`

دي النقطة اللي بتقيس بيها نجاح المشروع كله.

---

## الاختبارات المطلوبة قبل البرودكشن

- [ ] ملف 2 جيجا كامل
- [ ] قطع الشبكة في النص → استئناف
- [ ] إغلاق التاب → استئناف بعد إعادة الفتح
- [ ] **عزل المستأجرين**: `sign-part` بـ `video_id` بتاع مستأجر تاني → لازم 404
- [ ] `sign-part` على فيديو حالته `uploaded` → لازم 409
- [ ] `/complete` مرتين → 200 في المرتين، ومهمة ترميز واحدة بس
- [ ] `upload_driver=server` → السلوك القديم بالظبط، صورة ومدة وكله
- [ ] توقيت الـ migration على أكبر قاعدة بيانات مستأجر (`ALTER TABLE` بيقفل الجدول وهيتنفذ على كل مستأجر)

---

## خارج الريبو ده

| الحاجة | المكان | حالة |
|---|---|---|
| `probe-metadata` (صورة + مدة) | ريبو سيرفر الترميز | ⚠️ **إجباري** |
| سياسة CORS + lifecycle | داشبورد Cloudflare / wrangler | مطلوب |
| بكت staging منفصل | Cloudflare | مطلوب |
| `php.ini` | إعداد السيرفر | المرحلة 10 |

---

## التراجع

```env
HLS_VIDEO_UPLOAD_DRIVER=server
```

ثم `config:clear`. المسار القديم شغال زي ما هو، والأعمدة الجديدة بتفضل موجودة من غير ضرر، والفيديوهات المرفوعة بالمسار الجديد بتفضل شغالة لأنها في نفس مكان التخزين بالظبط.
