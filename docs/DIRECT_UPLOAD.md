# الرفع المباشر إلى R2 (direct upload)

رفع الفيديو من المتصفح إلى Cloudflare R2 مباشرةً، من غير ما الملف يعدّي على PHP.

المسار القديم (`upload_driver = server`) **مافيهوش أي تغيير** وهو الافتراضي، فالمشاريع التانية اللي بتستخدم الباكدج مش هتتأثر بالترقية.

---

## 1. المفتاح

```env
HLS_VIDEO_UPLOAD_DRIVER=direct     # الافتراضي: server
```

| المفتاح | الافتراضي | الوصف |
|---|---|---|
| `HLS_VIDEO_UPLOAD_DRIVER` | `server` | `server` \| `direct` |
| `HLS_VIDEO_UPLOADED_DISK` | `r2` | قرص S3 المتوافق اللي بيتخزّن عليه الأصل |
| `HLS_VIDEO_TEMP_PREFIX` | `temp-videos` | **لا تغيّره على تثبيت قائم** |
| `HLS_VIDEO_PART_SIZE` | `33554432` (32 MiB) | حجم الجزء الثابت |
| `HLS_VIDEO_MAX_FILE_SIZE` | `3221225472` (3 GiB) | السقف |
| `HLS_VIDEO_URL_TTL` | `30` | صلاحية رابط الجزء بالدقايق |
| `HLS_VIDEO_UPLOAD_THROTTLE` | `600,1` | حد الطلبات على راوتس التوقيع |
| `HLS_VIDEO_RECONCILE_AFTER` | `15` | بعد كام دقيقة نتحقق من الرفعات المعلّقة |
| `HLS_VIDEO_ABORT_AFTER` | `24` | بعد كام ساعة نلغي الرفعة المهجورة |
| `HLS_VIDEO_UPLOADER_ACCESS_URL` | `''` | بادئة URL لراوتس الرفع |

> **حجم الجزء**: R2 بيفرض إن كل الأجزاء تكون **بنفس الحجم** ماعدا الأخير، الحد الأدنى 5 MiB والأقصى 10,000 جزء. 32 MiB بيغطي ملف 320 جيجا نظرياً و64 جزء لملف 2 جيجا. لو طلابك على شبكات ضعيفة، 16 MiB بيخلي إعادة المحاولة أرخص.

## 2. الـ migration

```bash
php artisan migrate     # على كل قاعدة بيانات مستأجر
```

بيعمل حاجتين:

1. بيحوّل `hls_videos.status` من `ENUM` لـ `VARCHAR(20)` بـ `ALTER TABLE` خام. **ده بيعمل rebuild للجدول وبيقفله** — قيس الوقت على أكبر قاعدة بيانات في staging قبل البرودكشن. بعد الخطوة دي أي حالة جديدة مش هتحتاج migration تاني أبداً.
2. بيضيف `r2_key`, `r2_upload_id`, `upload_size`, `upload_started_at` + index على `(status, upload_started_at)`.

القيمة الافتراضية لـ `status` بتفضل `uploaded` فالمسار القديم زي ما هو.

## 3. إعداد البكت

**CORS** — الـ `ExposeHeaders` إجباري: من غير `ETag` مش هينفع Uppy يكمّل الرفع.

```json
[
  {
    "AllowedOrigins": ["https://tenant-a.example.com", "https://tenant-b.example.com"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

> ⚠️ **نقطة تحتاج اختبار**: توثيق R2 بيقول إن `AllowedOrigins` لازم تطابق بالظبط ومابيذكرش دعم wildcard. جرّب `https://*.example.com` بملف صغير الأول. لو مااشتغلش، اتنين حلول: تأتمت إضافة الـ origin عند إنشاء كل مستأجر، أو تحمّل واجهة الرفع في `iframe` من دومين واحد ثابت فيبقى الـ Origin واحد للأبد.
>
> انتشار سياسة الـ CORS بياخد لحد 30 ثانية.

**Lifecycle** — إلغاء الرفعات الناقصة بعد يوم (الافتراضي 7 أيام):

```bash
npx wrangler r2 bucket lifecycle add <BUCKET> \
  --name abort-incomplete --abort-multipart-days 1
```

## 4. الجدولة

```php
// app/Console/Kernel.php
$schedule->command('hls-videos:reconcile-uploads')->everyTenMinutes();
```

الأمر ده بيلف على كل المستأجرين وبيعمل حاجتين:

- **استرجاع**: رفعة خلصت على R2 بس الرد ما وصلش للسيرفر (التاب اتقفل، النت قطع، deploy في النص). `HeadObject` بيلاقي الملف فبيكمّل الحالة ويدفع الفيديو للترميز.
- **تنظيف**: رفعة مهجورة → `AbortMultipartUpload` عشان الأجزاء تبطّل تتحاسب، والصف يتعلّم `upload_failed`.

```bash
php artisan hls-videos:reconcile-uploads --dry-run          # معاينة
php artisan hls-videos:reconcile-uploads --tenant=12        # مستأجر واحد
```

## 5. الـ endpoints

كلها تحت `hls/videos/direct` وجوه `uploader_access_middleware` + throttle خاص.

| الطريقة | المسار | الجسم | الرد |
|---|---|---|---|
| POST | `init` | `filename`, `size`, `content_type`, `folder_id`, `model_type`, `model_id` | `video_id`, `key`, `uploadId`, `part_size` |
| POST | `{videoId}/sign-part` | `partNumber` | `url`, `expires_at` |
| GET | `{videoId}/parts` | — | `[{PartNumber, Size, ETag}]` |
| POST | `{videoId}/complete` | `parts[]` | نفس شكل رد `getOptions` |
| POST | `{videoId}/abort` | — | `{status: true}` |

**نموذج الأمان**: العميل بيبعت `video_id` و `partNumber` وبس. الـ key والـ `uploadId` بيتقروا من صف الفيديو على اتصال المستأجر الحالي، فمفيش طريقة يوجّه بيها الرفع لمسار تاني أو مستأجر تاني. زيادة في الاحتياط، كل نداء توقيع بيتأكد إن الـ key بيبدأ ببادئة المستأجر الحالي. والتوقيع مسموح بس والحالة `pending_upload` — يعني مستحيل حد يدوس على فيديو منشور.

## 6. مطلوب من سيرفر الترميز ⚠️

في المسار القديم، PHP كان بياخد الـ thumbnail والمدة من الملف المحلي قبل ما يرفعه. دلوقتي الملف مابيعدّيش على PHP خالص، فـ **سيرفر الترميز هو اللي لازم يرجّعهم**.

من غير التعديل ده، الفيديوهات المرفوعة بالمسار الجديد هتفضل من غير صورة ولا مدة.

بعد ما العقدة تنزّل الأصل وقبل ما تبدأ الترميز، تبعت:

```http
POST {main_server}/hls/videos/probe-metadata/{videoId}
Authorization: {steps_encoder_token}
Content-Type: application/json

{
  "tenant_id": 12,
  "duration": 3721.5,
  "width": 1920,
  "height": 1080,
  "thumb": "<base64 JPEG>"
}
```

- كل الحقول اختيارية ما عدا `tenant_id`.
- `thumb` صورة JPEG بـ base64 — الطبيعي frame عند الثانية 3، زي ما `createThumb` كانت بتعمل.
- السيرفر بيكتبها على نفس القرص ونفس المسار القديم (`{media_folder}/{video_id}/thumb.jpg`) وبيضبط `stream_data.thumb_disk`، فـ `HlsVideo::getThumbUrlAttribute` بيشتغل من غير أي تعديل.
- الأمر idempotent — النداء مرتين مش بيضر.

مثال ffmpeg على العقدة:

```bash
ffprobe -v quiet -print_format json -show_format -show_streams input.mp4
ffmpeg -ss 3 -i input.mp4 -frames:v 1 -q:v 3 thumb.jpg
```

## 7. خطة النشر على staging

1. بكت R2 منفصل + سياسة CORS + قاعدة lifecycle.
2. `HLS_VIDEO_UPLOAD_DRIVER=direct` + `HLS_VIDEO_UPLOADED_DISK=r2_staging`.
3. `php artisan migrate` ثم `route:clear && config:clear && view:clear`.
4. عدّل سيرفر الترميز عشان يبعت `probe-metadata` (البند 6).
5. جدول `hls-videos:reconcile-uploads`.
6. اختبر: ملف 2 جيجا، اقطع النت في النص وكمّل، اقفل التاب وافتحه تاني، وجرّب `sign-part` بـ `video_id` بتاع مستأجر تاني (لازم 404).
7. بعد ما يستقر: نزّل `upload_max_filesize` و `max_execution_time` لقيم عادية.

## 8. التراجع

```env
HLS_VIDEO_UPLOAD_DRIVER=server
```

ثم `config:clear`. المسار القديم شغال زي ما هو والأعمدة الجديدة بتفضل موجودة من غير ضرر. الفيديوهات اللي اترفعت بالمسار الجديد بتفضل شغالة عادي لأنها في نفس مكان التخزين بالظبط.
