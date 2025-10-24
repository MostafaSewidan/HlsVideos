@push('hls-styles')
    <link rel="stylesheet" href="https://releases.transloadit.com/uppy/v3.18.0/uppy.css">
    <style>
        .loader2 {
            width: 175px;
            height: 80px;
            display: block;
            margin: auto;
            background-image: radial-gradient(circle 25px at 25px 25px, #FFF 100%, transparent 0), radial-gradient(circle 50px at 50px 50px, #FFF 100%, transparent 0), radial-gradient(circle 25px at 25px 25px, #FFF 100%, transparent 0), linear-gradient(#FFF 50px, transparent 0);
            background-size: 50px 50px, 100px 76px, 50px 50px, 120px 40px;
            background-position: 0px 30px, 37px 0px, 122px 30px, 25px 40px;
            background-repeat: no-repeat;
            position: relative;
            box-sizing: border-box;
        }

        .loader2::before {
            content: '';
            left: 60px;
            bottom: 18px;
            position: absolute;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #FF3D00;
            background-image: radial-gradient(circle 8px at 18px 18px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 18px 0px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 0px 18px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 36px 18px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 18px 36px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 30px 5px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 30px 5px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 30px 30px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 5px 30px, #FFF 100%, transparent 0), radial-gradient(circle 4px at 5px 5px, #FFF 100%, transparent 0);
            background-repeat: no-repeat;
            box-sizing: border-box;
            animation: rotationBack 3s linear infinite;
        }

        .loader2::after {
            content: '';
            left: 94px;
            bottom: 15px;
            position: absolute;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: #FF3D00;
            background-image: radial-gradient(circle 5px at 12px 12px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 12px 0px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 0px 12px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 24px 12px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 12px 24px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 20px 3px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 20px 3px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 20px 20px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 3px 20px, #FFF 100%, transparent 0), radial-gradient(circle 2.5px at 3px 3px, #FFF 100%, transparent 0);
            background-repeat: no-repeat;
            box-sizing: border-box;
            animation: rotationBack 4s linear infinite reverse;
        }

        @keyframes rotationBack {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(-360deg);
            }
        }
    </style>
    <style>
        .loader {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: #fff;
            box-shadow: 32px 0 #fff, -32px 0 #fff;
            position: relative;
            animation: flash 0.3s ease-in infinite alternate;
        }

        .loader::before,
        .loader::after {
            content: '';
            position: absolute;
            left: -64px;
            top: 0;
            background: #FFF;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            transform-origin: 35px -35px;
            transform: rotate(45deg);
            animation: hitL 0.3s ease-in infinite alternate;
        }

        .loader::after {
            left: 64px;
            transform: rotate(-45deg);
            transform-origin: -35px -35px;
            animation: hitR 0.3s ease-out infinite alternate;
        }

        @keyframes flash {

            0%,
            100% {
                background-color: rgba(255, 255, 255, 0.25);
                box-shadow: 32px 0 rgba(255, 255, 255, 0.25), -32px 0 rgba(255, 255, 255, 0.25);
            }

            25% {
                background-color: rgba(255, 255, 255, 0.25);
                box-shadow: 32px 0 rgba(255, 255, 255, 0.25), -32px 0 rgba(255, 255, 255, 1);
            }

            50% {
                background-color: rgba(255, 255, 255, 1);
                box-shadow: 32px 0 rgba(255, 255, 255, 0.25), -32px 0 rgba(255, 255, 255, 0.25);
            }

            75% {
                background-color: rgba(255, 255, 255, 0.25);
                box-shadow: 32px 0 rgba(255, 255, 255, 1), -32px 0 rgba(255, 255, 255, 0.25);
            }
        }

        @keyframes hitL {
            0% {
                transform: rotate(45deg);
                background-color: rgba(255, 255, 255, 1);
            }

            25%,
            100% {
                transform: rotate(0deg);
                background-color: rgba(255, 255, 255, 0.25);
            }
        }

        @keyframes hitR {

            0%,
            75% {
                transform: rotate(0deg);
                background-color: rgba(255, 255, 255, 0.25);
            }

            100% {
                transform: rotate(-45deg);
                background-color: rgba(255, 255, 255, 1);
            }
        }

        .loader {
            width: 48px;
            height: 48px;
            border: 5px solid #337ab7;
            border-bottom-color: transparent;
            border-radius: 50%;
            display: inline-block;
            box-sizing: border-box;
            animation: rotation 1s linear infinite;
        }

        @keyframes rotation {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .uppy-Dashboard-inner,
        .uppy-StatusBar.is-waiting .uppy-StatusBar-actions,
        .uppy-DashboardContent-bar {
            background: white;
        }

        .uppy-Dashboard-Item-previewInnerWrap,
        .uppy-Dashboard--singleFile .uppy-Dashboard-Item-previewInnerWrap {
            background: rgb(57 57 57) !important;
        }

        .uppy-StatusBar.is-waiting .uppy-StatusBar-actionBtn--upload,
        .uppy-StatusBar.is-waiting .uppy-StatusBar-actionBtn--upload:hover {
            background: rgb(52 152 220) !important;
        }

        .uppy-Dashboard--singleFile.uppy-size--md .uppy-Dashboard-Item-preview {
            max-height: 75%;
        }

        /* Existing Videos Panel Styles */
        #drag-drop-area {
            position: relative;
        }

        .existing-videos-panel {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: white;
            z-index: 1000;
            display: none;
            flex-direction: column;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .existing-videos-panel.active {
            display: flex;
        }

        .existing-videos-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 2px solid #e9ecef;
            background: #f8f9fa;
        }

        .existing-videos-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        #existing-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .existing-breadcrumb-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            color: #495057;
            transition: background 0.2s;
        }

        .existing-breadcrumb-item:hover {
            background: #e9ecef;
        }

        .existing-breadcrumb-item.current {
            background: #e7f1ff;
            color: #4361ee;
            font-weight: 600;
            cursor: default;
        }

        .existing-breadcrumb-item.current:hover {
            background: #e7f1ff;
        }

        .existing-breadcrumb-item i {
            color: #4361ee;
        }

        .existing-breadcrumb-separator {
            color: #6c757d;
        }

        .existing-folder-item {
            display: flex;
            align-items: center;
            padding: 0px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8f9fa;
        }

        .existing-folder-item:hover {
            border-color: #ffb347;
            background: #fff9f0;
        }

        .existing-folder-icon {
            font-size: 35px;
            color: #ffb347;
            margin-left: 12px;
            margin-right: 12px;
        }

        .existing-folder-info {
            flex: 1;
        }

        .existing-folder-title {
            font-size: 15px;
            font-weight: 600;
            color: #333;
        }

        .existing-folder-meta {
            font-size: 13px;
            color: #6c757d;
            margin-top: 4px;
        }

        .close-existing-panel {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .close-existing-panel:hover {
            background: #e9ecef;
            color: #333;
        }

        .existing-videos-search {
            padding: 16px 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .existing-videos-filters {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
        }

        .existing-videos-search input {
            width: 100%;
            padding: 10px 16px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
        }

        .existing-videos-search input:focus {
            outline: none;
            border-color: #4361ee;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .filter-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #6c757d;
            cursor: pointer;
            user-select: none;
        }

        .filter-toggle input[type="checkbox"] {
            cursor: pointer;
        }

        .videos-count {
            font-size: 13px;
            color: #6c757d;
            padding: 8px 0;
        }

        .existing-videos-content {
            position: relative;
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
            max-height: 400px; 
        }

        .existing-video-item {
            display: flex;
            align-items: center;
            padding: 5px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .existing-video-item:hover {
            border-color: #4361ee;
            background: #f8f9ff;
        }

        .existing-video-item.selected {
            border-color: #4361ee;
            background: #e7f1ff;
        }

        .existing-video-thumbnail {
            width: 75px;
            height: 55px;
            background: #f0f0f0;
            border-radius: 6px;
            margin-left: 12px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .existing-video-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .existing-video-info {
            flex: 1;
            margin-right: 12px;
        }

        .existing-video-title {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }

        .existing-video-meta {
            font-size: 13px;
            color: #6c757d;
        }

        .existing-video-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }

        .status-ready {
            background: #d4edda;
            color: #155724;
        }

        .status-processing {
            background: #fff3cd;
            color: #856404;
        }

        .existing-videos-footer {
            padding: 16px 20px;
            border-top: 2px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn-select-video {
            background: #4361ee;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-select-video:hover {
            background: #3f37c9;
        }

        .btn-select-video:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        .empty-existing-videos {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 300px;
            color: #6c757d;
        }

        .empty-existing-videos i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .existing-videos-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 300px;
        }

        .existing-videos-loading .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4361ee;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .existing-videos-loading p {
            color: #6c757d;
            margin-top: 16px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .items-separator {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 16px 0;
            color: #6c757d;
            font-size: 13px;
            font-weight: 600;
        }

        .items-separator::before,
        .items-separator::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #dee2e6;
        }

        /* Responsive styles for mobile */
        @media (max-width: 768px) {
            .existing-video-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .existing-video-thumbnail {
                width: 100%;
                height: 180px;
                margin: 0 0 12px 0;
            }

            .existing-video-info {
                width: 100%;
                margin: 0;
            }

            .existing-videos-filters {
                flex-direction: column;
                gap: 8px;
            }

            .btn-select-video {
                width: 100%;
            }

            .existing-folder-item {
                padding: 8px;
            }

            .existing-folder-icon {
                font-size: 36px;
                margin: 0 8px;
            }
        }
        
        /* زر عرض المزيد */
        .hls-load-more-btn {
            position: relative;
            left: 50%;
            transform: translateX(-50%);
            background: #007bff;
            color: white;
            border: none;
            border-radius: 25px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .hls-load-more-btn:hover {
            background: #0056b3;
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 123, 255, 0.4);
        }

        .hls-load-more-btn:active {
            transform: translateX(-50%) translateY(0);
        }

        .hls-load-more-btn.loading {
            background: #6c757d;
            cursor: not-allowed;
        }

        .hls-load-more-btn.loading i {
            animation: spin 1s linear infinite;
        }

        .hls-load-more-btn.hidden {
            opacity: 0;
            visibility: hidden;
            transform: translateX(-50%) translateY(20px);
        }

        /* مؤشر التحميل */
        .loading-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #6c757d;
        }

        .loading-indicator .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* تحسينات للهواتف المحمولة */
        @media (max-width: 768px) {
            .hls-load-more-btn {
                bottom: 80px; /* أعلى قليلاً لتجنب التداخل مع التذييل */
                padding: 14px 28px;
                font-size: 16px;
            }
            
            .file-manager-content {
                padding-bottom: 100px; /* مساحة إضافية للزر */
            }
        }

        .hls-loading-more,
        .hls-no-more-items {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            grid-column: 1 / -1;
            color: #6c757d;
            font-size: 14px;
        }

        .hls-no-more-items i {
            margin-right: 8px;
            color: #4cc9f0;
        }
        /* Fixed scroll container */
        .existing-videos-scroll-container {
            position: relative;
            flex: 1;
            overflow: hidden; /* Hide outer scroll */
            display: flex;
            flex-direction: column;
        }

        .existing-videos-content {
            flex: 1;
            overflow-y: auto; /* Enable scrolling */
            max-height: 400px; /* Fixed height for scrolling */
            padding: 16px 20px;
        }

        /* Ensure content is scrollable */
        .existing-videos-panel {
            display: none;
            flex-direction: column;
            height: 600px; /* Fixed panel height */
            max-height: 80vh;
        }

        .existing-videos-panel.active {
            display: flex;
        }

        /* Load more button positioning */
        .hls-load-more-btn {
            margin: 16px auto;
            display: none;
        }

        .hls-load-more-btn:not(.hidden) {
            display: flex;
        }

        /* Footer layout */
        .existing-videos-footer {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 16px 20px;
            border-top: 2px solid #e9ecef;
        }

        .footer-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Responsive fixes */
        @media (max-width: 768px) {
            .existing-videos-panel {
                height: 70vh;
            }
            
            .existing-videos-content {
                max-height: 300px;
            }
            
            .footer-buttons {
                flex-direction: column;
            }
            
            .footer-buttons button {
                width: 100%;
            }
        }
    </style>
    @include('hls-videos::components._cssvideo')
@endpush

<div id="video-options-card"></div>
<div class="col-md-12 text-center" id="video_loader">
    <span class="loader"></span>
</div>


@push('hls-scripts')
    <script src="https://releases.transloadit.com/uppy/v3.18.0/uppy.min.js"></script>
    <script>
        let uppy = null;
        let modelType = "{{ $model ? str_replace('\\', '\\\\', get_class($model)) : null }}";
        let modelId = "{{ $model?->id }}";
        @if ($video?->id)
            let videoId = "{{ $video?->id }}";
        @else
            let videoId = null;
        @endif

        function setupVideoUpload() {
            var folderIdInput = document.getElementById("current_folder_id");

            var folderId = null;
            if (folderIdInput) {
                folderId = folderIdInput.value;
            }

            uppy = new Uppy.Uppy({
                restrictions: {
                    maxNumberOfFiles: 1, // ✅ Allow only one file
                    maxFileSize: 1200 * 1024 * 1024, // 50MB
                    allowedFileTypes: [
                        'video/*' // ✅ Accept ALL video formats
                    ]
                },
                autoProceed: false, // Automatically start uploading after the file is selected
                parallel: true, // Enable parallel uploads of chunks
                chunkSize: 10 * 1024 * 1024 // 10MB per chunk (can be adjusted)
            });

            // ✅ Remove previous file when a new one is added
            uppy.on('file-added', (file) => {
                if (uppy.getFiles().length > 1) {
                    const previousFile = uppy.getFiles()[0]; // Get the first file
                    uppy.removeFile(previousFile.id); // Remove the previous file
                }
                setTimeout(() => {
                    const fileId = file.id;
                    const previewWrapper = document.querySelector(
                        `.uppy-Dashboard-Item-previewIconWrap`
                    );

                    if (previewWrapper) {
                        // Clear existing preview content (icon)
                        previewWrapper.innerHTML = '';

                        // Create a video preview element
                        const video = document.createElement('video');
                        video.src = URL.createObjectURL(file.data);
                        video.controls = true;
                        video.className = 'uppy-video-preview';
                        video.style.width = '100%';
                        video.muted = false;
                        video.autoplay = false;

                        previewWrapper.appendChild(video);
                    }
                }, 100); // Slight delay to ensure DOM is ready
            });

            // ✅ Add Dashboard UI with remote upload options
            uppy.use(Uppy.Dashboard, {
                inline: true,
                target: "#drag-drop-area", // The element where Uppy will be displayed
                showProgressDetails: true,
                proudlyDisplayPoweredByUppy: false,
                plugins: [
                    'Webcam',
                    'ScreenCapture',
                ]
            });

            // ✅ Add Webcam for camera recording
            uppy.use(Uppy.Webcam, {
                target: Uppy.Dashboard,
                modes: ['video-only'], // Record video only
            });

            // ✅ Add Screen Capture for screen recording
            uppy.use(Uppy.ScreenCapture, {
                target: Uppy.Dashboard
            });

            // Delay to allow Uppy to fully mount
            setTimeout(() => {
                const sidebar = document.querySelector('.uppy-Dashboard-AddFiles-list');

                if (sidebar) {
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = `
                    <div class="uppy-DashboardTab" role="presentation" data-uppy-acquirer-id="choose-from-existing">
                        <button 
                        type="button" 
                        class="uppy-u-reset uppy-c-btn uppy-DashboardTab-btn" 
                        role="tab" 
                        tabindex="0"
                        id="existing-videos-tab"
                        >
                        <div class="uppy-DashboardTab-inner">
                            <i class="fa fa-cloud" style="
                                font-size: 1.2rem;
                                color: #FF3D00;
                            "></i>
                        </div>
                        <div class="uppy-DashboardTab-name">مكتبة الفيديوهات </div>
                        </button>
                    </div>
                    `;

                    const tab = wrapper.firstElementChild;
                    const tabButton = tab.querySelector('#existing-videos-tab');

                    // Add click event to show existing videos panel
                    if (tabButton) {
                        tabButton.addEventListener('click', function(e) {
                            e.preventDefault();
                            showExistingVideosPanel();
                        });
                    }

                    sidebar.appendChild(tab);
                } else {
                    console.error('Uppy sidebar not found');
                }
            }, 500); // Allow dashboard to render

            // Setup existing videos panel functionality
            setupExistingVideosPanel();

            // Set meta before upload
            uppy.setMeta({
                model_type: "{{ $model ? str_replace('\\', '\\\\', get_class($model)) : null }}",
                model_id: "{{ $model?->id }}",
                folder_id: folderId,
            });

            // Add XHRUpload plugin for chunk upload handling
            uppy.use(Uppy.XHRUpload, {
                endpoint: '{{ route('hls.videos.upload') }}', // Your backend endpoint for receiving chunks
                formData: true,
                fieldName: 'file',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                // You can add custom options for parallelism and retries
                parallelUploads: 5, // Limit to 5 parallel uploads
                // Other useful options:
                bundle: false, // must be false for chunked upload
                limit: 1, // upload 1 chunk at a time
                allowMultipleUploads: false
                // - withCredentials: true (if you need to send credentials with requests)
            });

            // ========== إضافة أحداث الرفع ==========

            uppy.on('upload', (data) => {
                fireVideoStartedUploadingEvent(data);
            });

            uppy.on('upload-progress', (file, progress) => {
                fireVideoUploadProgressEvent(file, progress);
            });

            uppy.on('upload-success', (file, response) => {
                videoId = response.body.video_id;
                setVideoOptionCard(response.body);
                fireVideoUploadCompleteEvent(file, response);
            });

            uppy.on('upload-error', (file, error, response) => {
                fireVideoUploadErrorEvent(file, error, response);
            });
        }

        // ========== دوال الفيديوهات الموجودة ==========

        let existingVideosState = {
            currentFolderId: null,
            allVideos: [],
            allFolders: [],
            filteredVideos: [],
            filteredFolders: [],
            selectedVideo: null,
            isLoading: false,
            breadcrumb: [],
            isSharedMode: false,
            pagination: {
                currentPage: 1,
                lastPage: 1,
                perPage: 20,
                total: 0,
                hasMore: false,
                isLoading: false,
            }
        };

        // ========== دوال معالجة Pagination والتمرير اللانهائي ==========

        function setupInfiniteScrollHls() {
            const scrollContainer = document.querySelector(".existing-videos-content");
            const loadMoreBtn = document.getElementById("hls-load-more-button");

            if (scrollContainer) {
                scrollContainer.removeEventListener("scroll", handleScrollHls);
                scrollContainer.addEventListener("scroll", handleScrollHls);
            }

            if (loadMoreBtn) {
                loadMoreBtn.removeEventListener("click", handleLoadMoreBtnHls);
                loadMoreBtn.addEventListener("click", handleLoadMoreBtnHls);
                updateLoadMoreButton();
            }
        }

        function handleScrollHls() {
            if (existingVideosState.pagination.isLoading) return;
            const scrollContainer = document.querySelector(".existing-videos-content");
            if (!scrollContainer) return;

            const scrollTop = scrollContainer.scrollTop || document.documentElement.scrollTop;
            const scrollHeight =
                scrollContainer.scrollHeight || document.documentElement.scrollHeight;
            const clientHeight =
                scrollContainer.clientHeight || document.documentElement.clientHeight;
            const nearBottom = scrollTop + clientHeight >= scrollHeight - 200;

            if (nearBottom && existingVideosState.pagination.hasMore) {
                loadNextPageHls();
            }
        }

        function handleLoadMoreBtnHls(event) {
            event.preventDefault();
            event.stopPropagation();
            if (existingVideosState.pagination.isLoading) return;
            if (existingVideosState.pagination.hasMore) {
                loadNextPageHls();
            }
        }

        function updateLoadMoreButton() {
            const loadMoreBtn = document.getElementById("hls-load-more-button");
            if (!loadMoreBtn) return;

            if (existingVideosState.pagination.hasMore && !existingVideosState.pagination.isLoading) {
                loadMoreBtn.style.display = 'flex';
                loadMoreBtn.disabled = false;
                loadMoreBtn.innerHTML = '<i class="fas fa-arrow-down"></i> تحميل المزيد';
            } else if (existingVideosState.pagination.isLoading) {
                loadMoreBtn.style.display = 'none';
                loadMoreBtn.disabled = true;
            } else {
                loadMoreBtn.style.display = 'none';
            }
        }

        function loadNextPageHls() {
            if (existingVideosState.pagination.isLoading || !existingVideosState.pagination.hasMore) return;
            
            existingVideosState.pagination.isLoading = true;
            existingVideosState.pagination.currentPage++;

            // Update UI
            updateLoadMoreButton();
            showLoadingMoreHls();

            // Build API URL
            console.log(existingVideosState.currentFolderId);
            
            const baseUrl = `/hls/folders/list?id=${existingVideosState.currentFolderId}`;
            
            const apiUrl = `${baseUrl}&page=${existingVideosState.pagination.currentPage}`;
            
            fetch(apiUrl, {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then((response) => {
                return response.json();
            })
            .then((data) => {
                if (data.success) {
                    handleNextPageDataHls(data.data || data);
                } else {
                    throw new Error(data.message || "Failed to load next page");
                }
            })
            .catch((error) => {
                console.error('❌ API error:', error);
                toastr.error("فشل تحميل المزيد من العناصر");
                // Rollback page number on error
                existingVideosState.pagination.currentPage--;
            })
            .finally(() => {
                existingVideosState.pagination.isLoading = false;
                hideLoadingMoreHls();
                updateLoadMoreButton();
            });
        }

        function handleNextPageDataHls(responseData) {
            if (!responseData) return;

            // Extract new data
            const newVideos = responseData.videos?.data || responseData.videos || [];
            const newFolders = responseData.folders || [];

            // Merge new data with existing data
            existingVideosState.allVideos = [
                ...existingVideosState.allVideos, 
                ...newVideos.filter(v => v.status === 'ready')
            ];
            existingVideosState.filteredVideos = existingVideosState.allVideos;

            // Update pagination info
            const paginationInfo = responseData.videos?.pagination || responseData.videos?.meta || responseData.pagination || {};
            existingVideosState.pagination.currentPage = paginationInfo.current_page || existingVideosState.pagination.currentPage;
            existingVideosState.pagination.lastPage = paginationInfo.last_page || existingVideosState.pagination.lastPage;
            existingVideosState.pagination.perPage = paginationInfo.per_page || existingVideosState.pagination.perPage;
            existingVideosState.pagination.total = paginationInfo.total || existingVideosState.pagination.total;
            existingVideosState.pagination.hasMore = existingVideosState.pagination.currentPage < existingVideosState.pagination.lastPage;

            // Update UI
            displayExistingVideos();
            updateVideosCount();

            if (!existingVideosState.pagination.hasMore) {
                showNoMoreItemsHls();
            }
        }

        function showLoadingMoreHls() {
            hideLoadingMoreHls();
            const content = document.getElementById('existing-videos-content');
            if (!content) return;

            const loadingHTML = `
                <div class="hls-loading-more" id="hls-loading-more">
                    <div class="spinner" style="width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #007bff; border-radius: 50%; animation: spin 1s linear infinite; margin-right: 10px;"></div>
                    <span>جاري تحميل المزيد...</span>
                </div>
            `;
            content.insertAdjacentHTML('beforeend', loadingHTML);
        }

        function hideLoadingMoreHls() {
            const loadingElement = document.getElementById('hls-loading-more');
            if (loadingElement) {
                loadingElement.remove();
            }
        }

        function showNoMoreItemsHls() {
            const content = document.getElementById('existing-videos-content');
            if (!content) return;

            // Remove previous no more items message
            const prevNoMore = document.getElementById('hls-no-more-items');
            if (prevNoMore) prevNoMore.remove();

            const noMoreHTML = `
                <div class="hls-no-more-items" id="hls-no-more-items">
                    <i class="fas fa-check-circle mx-2" style="color: #28a745; margin-right: 8px;"></i>
                    <span>لا توجد المزيد من العناصر للتحميل</span>
                </div>
            `;
            content.insertAdjacentHTML('beforeend', noMoreHTML);
        }

        function setupExistingVideosPanel() {
            const closeBtn = document.getElementById('close-existing-panel');
            const cancelBtn = document.getElementById('cancel-select-video');
            const confirmBtn = document.getElementById('confirm-select-video');
            const searchInput = document.getElementById('existing-videos-search');
            const showAllCheckbox = document.getElementById('show-all-folders-videos');

            if (closeBtn) {
                closeBtn.addEventListener('click', hideExistingVideosPanel);
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', hideExistingVideosPanel);
            }

            if (confirmBtn) {
                confirmBtn.addEventListener('click', confirmVideoSelection);
            }

            if (searchInput) {
                searchInput.addEventListener('input', filterExistingVideos);
            }

            if (showAllCheckbox) {
                showAllCheckbox.addEventListener('change', function() {
                    // Reset to initial folder when toggling
                    const folderIdInput = document.getElementById('current_folder_id');
                    existingVideosState.currentFolderId = this.checked ? null : (folderIdInput ? folderIdInput.value : null);
                    existingVideosState.selectedVideo = null;

                    const confirmBtn = document.getElementById('confirm-select-video');
                    if (confirmBtn) {
                        confirmBtn.disabled = true;
                    }

                    // Reset pagination when switching modes
                    existingVideosState.pagination.currentPage = 1;
                    existingVideosState.pagination.hasMore = false;

                    loadExistingVideos();
                });
            }
        }

        function showExistingVideosPanel() {
            const panel = document.getElementById('existing-videos-panel');
            if (panel) {
                panel.classList.add('active');

                // Initialize with current folder from file system
                const folderIdInput = document.getElementById('current_folder_id');
                existingVideosState.currentFolderId = folderIdInput ? folderIdInput.value : null;

                // Reset pagination state
                existingVideosState.pagination.currentPage = 1;
                existingVideosState.pagination.hasMore = false;

                loadExistingVideos();

                // Initialize infinite scroll when panel is shown
                setTimeout(() => {
                    setupInfiniteScrollHls();
                }, 100);

                // Add keyboard event listener
                document.addEventListener('keydown', handleExistingPanelKeyboard);
            }
        }

        function handleExistingPanelKeyboard(e) {
            const panel = document.getElementById('existing-videos-panel');
            if (!panel || !panel.classList.contains('active')) return;

            if (e.key === 'Escape') {
                e.preventDefault();
                hideExistingVideosPanel();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const confirmBtn = document.getElementById('confirm-select-video');
                if (confirmBtn && !confirmBtn.disabled) {
                    confirmVideoSelection();
                }
            }
        }

        function hideExistingVideosPanel() {
            const panel = document.getElementById('existing-videos-panel');
            if (panel) {
                panel.classList.remove('active');

                // Reset state
                existingVideosState.selectedVideo = null;
                existingVideosState.currentFolderId = null;
                existingVideosState.breadcrumb = [];
                existingVideosState.pagination.currentPage = 1;
                existingVideosState.pagination.hasMore = false;

                // Reset form elements
                const searchInput = document.getElementById('existing-videos-search');
                const confirmBtn = document.getElementById('confirm-select-video');
                const showAllCheckbox = document.getElementById('show-all-folders-videos');

                if (searchInput) {
                    searchInput.value = '';
                }

                if (confirmBtn) {
                    confirmBtn.disabled = true;
                }

                if (showAllCheckbox) {
                    showAllCheckbox.checked = false;
                }

                // Remove keyboard event listener
                document.removeEventListener('keydown', handleExistingPanelKeyboard);
            }
        }

        function loadExistingVideos(folderId = null) {
            const content = document.getElementById('existing-videos-content');
            const showAllCheckbox = document.getElementById('show-all-folders-videos');
            const showAllFolders = showAllCheckbox ? showAllCheckbox.checked : false;

            // Use provided folder ID or current state folder ID
            var targetFolderId = folderId ?? existingVideosState.currentFolderId;

            if(!folderId && existingVideosState.isSharedMode) {
                targetFolderId = null;
            }
            // Show loading state
            content.innerHTML = `
                <div class="existing-videos-loading">
                    <div class="spinner"></div>
                    <p style="margin-top: 16px;">جاري التحميل...</p>
                </div>
            `;

            // Hide load more button during initial load
            updateLoadMoreButton();

            // Build API endpoint
            let apiUrl = targetFolderId ? `/hls/folders/list?id=${targetFolderId}` : '/hls/folders/list';
            
            fetch(apiUrl, {
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let videos = [];
                        let folders = [];
                        const responseData = data.data || data;
                        existingVideosState.isSharedMode = responseData.is_shared_mode;

                        if (showAllFolders) {
                            // For search results, extract videos from the data structure
                            const videoResults = responseData.videos?.data || responseData.videos || [];
                            videos = videoResults.map(item => item.video || item);
                            folders = []; // Don't show folders when searching all
                        } else {
                            // For folder list, extract both folders and videos
                            videos = responseData.videos?.data || responseData.videos || [];
                            folders = responseData.folders || [];
                            if (existingVideosState.isSharedMode) {
                                existingVideosState.breadcrumb = [
                                    { id: null, title: 'الرئيسية' },
                                    ...responseData.breadcrumb,
                                ];
                            } else {
                                existingVideosState.breadcrumb = responseData.breadcrumb || [];
                            }
                            existingVideosState.currentFolderId = targetFolderId;
                        }

                        if(!existingVideosState.currentFolderId) {
                            existingVideosState.currentFolderId = data.data.folder?.id;
                        }

                        existingVideosState.allVideos = videos.filter(v => v.status === 'ready');
                        existingVideosState.allFolders = folders;
                        existingVideosState.filteredVideos = existingVideosState.allVideos;
                        existingVideosState.filteredFolders = existingVideosState.allFolders;

                        // Update pagination info from initial load
                        const paginationInfo = responseData.videos?.pagination || responseData.videos?.meta || responseData.pagination || {};
                        existingVideosState.pagination.currentPage = paginationInfo.current_page || 1;
                        existingVideosState.pagination.lastPage = paginationInfo.last_page || 1;
                        existingVideosState.pagination.perPage = paginationInfo.per_page || 20;
                        existingVideosState.pagination.total = paginationInfo.total || 0;
                        existingVideosState.pagination.hasMore = existingVideosState.pagination.currentPage < existingVideosState.pagination.lastPage;

                        displayExistingVideos();
                        updateExistingBreadcrumb();
                        updateVideosCount();
                    } else {
                        throw new Error(data.message || 'Failed to load content');
                    }
                })
                .catch(error => {
                    console.error('Error loading existing videos:', error);
                    content.innerHTML = `
                    <div class="empty-existing-videos">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>فشل تحميل المحتوى</p>
                        <button class="btn btn-primary mt-3" onclick="loadExistingVideos()">
                            حاول مرة أخرى
                        </button>
                    </div>
                `;
                });
        }

        function updateExistingBreadcrumb() {
            const breadcrumbContainer = document.getElementById('existing-breadcrumb');
            const showAllCheckbox = document.getElementById('show-all-folders-videos');
            const showAllFolders = showAllCheckbox ? showAllCheckbox.checked : false;

            if (!breadcrumbContainer || showAllFolders) {
                if (breadcrumbContainer) {
                    breadcrumbContainer.style.display = 'none';
                }
                return;
            }

            const breadcrumb = existingVideosState.breadcrumb || [];

            if (breadcrumb.length === 0) {
                breadcrumbContainer.style.display = 'none';
                return;
            }

            breadcrumbContainer.style.display = 'flex';
            breadcrumbContainer.innerHTML = '';

            breadcrumb.forEach((item, index) => {
                const breadcrumbItem = document.createElement('div');
                const isLast = index === breadcrumb.length - 1;
                breadcrumbItem.className = `existing-breadcrumb-item ${isLast ? 'current' : ''}`;
                breadcrumbItem.innerHTML = `
                    <i class="fas fa-folder${isLast ? '-open' : ''}"></i>
                    <span>${item.title}</span>
                `;

                // Only add click event if not the current folder
                if (!isLast) {
                    breadcrumbItem.addEventListener('click', () => {
                        if (item.id) {
                            navigateToExistingFolder(item.id);
                        } else {
                            navigateToExistingFolder(null);
                        }
                    });
                }

                breadcrumbContainer.appendChild(breadcrumbItem);

                if (index < breadcrumb.length - 1) {
                    const separator = document.createElement('div');
                    separator.className = 'existing-breadcrumb-separator';
                    separator.innerHTML = '<i class="fas fa-chevron-left"></i>';
                    breadcrumbContainer.appendChild(separator);
                }
            });
        }

        function navigateToExistingFolder(folderId) {
            existingVideosState.selectedVideo = null;
            document.getElementById('confirm-select-video').disabled = true;
            loadExistingVideos(folderId);
        }

        function updateVideosCount() {
            const countElement = document.getElementById('videos-count');
            if (countElement) {
                const folderCount = existingVideosState.filteredFolders.length;
                const videoCount = existingVideosState.filteredVideos.length;
                const totalItems = folderCount + videoCount;

                if (folderCount > 0 && videoCount > 0) {
                    countElement.textContent = `${folderCount} @lang('folder(s)'), ${videoCount} @lang('video(s)')`;
                } else if (folderCount > 0) {
                    countElement.textContent = `${folderCount} @lang('folder(s)')`;
                } else if (videoCount > 0) {
                    countElement.textContent = `${videoCount} @lang('video(s)')`;
                } else {
                    countElement.textContent = '@lang('No items')';
                }
            }
        }

        function displayExistingVideos() {
            const content = document.getElementById('existing-videos-content');
            const videos = existingVideosState.filteredVideos;
            const folders = existingVideosState.filteredFolders;
            const showAllCheckbox = document.getElementById('show-all-folders-videos');
            const showAllFolders = showAllCheckbox ? showAllCheckbox.checked : false;

            if (videos.length === 0 && folders.length === 0) {
                const searchTerm = document.getElementById('existing-videos-search').value;
                const message = searchTerm ?
                    '@lang('No items match your search')' :
                    '@lang('This folder is empty')';

                content.innerHTML = `
                    <div class="empty-existing-videos">
                        <i class="fas fa-folder-open"></i>
                        <p>${message}</p>
                        ${searchTerm ? '<small>@lang('Try a different search term or clear the search')</small>' : ''}
                    </div>
                `;
                return;
            }

            let html = '';

            // Render folders first
            if (folders.length > 0) {
                folders.forEach(folder => {
                    html += `
                        <div class="existing-folder-item" 
                             data-folder-id="${folder.id}"
                             onclick="openExistingFolder(${folder.id})"
                             ondblclick="openExistingFolder(${folder.id})">
                            <div class="existing-folder-icon">
                                <i class="fas fa-folder"></i>
                            </div>
                            <div class="existing-folder-info">
                                <div class="existing-folder-title">${folder.title}</div>
                                <div class="existing-folder-meta">
                                    <i class="fas fa-folder mx-1"></i>@lang('Folder')
                                    ${folder.created_at ? ` • ${folder.created_at}` : ''}
                                </div>
                            </div>
                            <div>
                                <i class="fas fa-chevron-left" style="font-size: 15px; color: #6c757d;"></i>
                            </div>
                        </div>
                    `;
                });

                // Add separator if there are also videos
                if (videos.length > 0) {
                    html += '<div class="items-separator"><i class="fas fa-video mx-1"></i>الفيديوهات</div>';
                }
            }

            // Then render videos
            videos.forEach(video => {
                // Build folder path if showing all folders
                let folderPath = '';
                if (showAllFolders && video.breadcrumb && video.breadcrumb.length > 0) {
                    folderPath = video.breadcrumb.map(crumb => crumb.title).join(' / ');
                }

                html += `
                    <div class="existing-video-item ${existingVideosState.selectedVideo?.id === video.id ? 'selected' : ''}" 
                         data-video-id="${video.id}"
                         onclick="selectExistingVideo(${video.id})">
                        <div class="existing-video-thumbnail">
                            ${video.thumb_url ? `<img src="${video.thumb_url}" alt="${video.title}">` : '<i class="fas fa-video fa-2x"></i>'}
                        </div>
                        <div class="existing-video-info">
                            <div class="existing-video-title">${video.title}</div>
                            <div class="existing-video-meta">
                                <span class="existing-video-status status-${video.status}">
                                    ${video.status === 'ready' ? '@lang('Ready')' : '@lang('Processing')'}
                                </span>
                                ${video.stream_data?.duration ? `<span>• ${video.stream_data.duration} @lang('seconds')</span>` : ''}
                                ${video.created_at ? `<span>• ${video.created_at}</span>` : ''}
                            </div>
                            ${folderPath ? `<div style="font-size: 12px; color: #999; margin-top: 4px;"><i class="fas fa-folder mx-1"></i>${folderPath}</div>` : ''}
                        </div>
                        <div>
                            <i class="fas fa-check-circle" style="font-size: 24px; color: ${existingVideosState.selectedVideo?.id === video.id ? '#4361ee' : '#e9ecef'};"></i>
                        </div>
                    </div>
                `;
            });

            content.innerHTML = html;
            
            // Update load more button after rendering
            updateLoadMoreButton();
        }

        function openExistingFolder(folderId) {
            navigateToExistingFolder(folderId);
        }

        function selectExistingVideo(videoId) {
            const video = existingVideosState.filteredVideos.find(v => v.id === videoId);

            if (video) {
                existingVideosState.selectedVideo = video;

                // Update UI
                document.querySelectorAll('.existing-video-item').forEach(item => {
                    item.classList.remove('selected');
                });

                const selectedItem = document.querySelector(`[data-video-id="${videoId}"]`);
                if (selectedItem) {
                    selectedItem.classList.add('selected');
                }

                // Update check icons
                displayExistingVideos();

                // Enable confirm button
                document.getElementById('confirm-select-video').disabled = false;
            }
        }

        function filterExistingVideos() {
        
            const searchTerm = document.getElementById('existing-videos-search').value.toLowerCase();

            if (searchTerm) {
                // Filter videos
                existingVideosState.filteredVideos = existingVideosState.allVideos.filter(video =>
                    video.title.toLowerCase().includes(searchTerm) ||
                    (video.video_id && video.video_id.toString().includes(searchTerm))
                );

                // Filter folders
                existingVideosState.filteredFolders = existingVideosState.allFolders.filter(folder =>
                    folder.title.toLowerCase().includes(searchTerm)
                );
            } else {
                existingVideosState.filteredVideos = existingVideosState.allVideos;
                existingVideosState.filteredFolders = existingVideosState.allFolders;
            }

            displayExistingVideos();
            updateVideosCount();
        }

        function confirmVideoSelection() {
            if (!existingVideosState.selectedVideo) return;

            const video = existingVideosState.selectedVideo;
            const confirmBtn = document.getElementById('confirm-select-video');
            const originalBtnText = confirmBtn.innerHTML;

            // Disable button and show loading
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = `
                <div class="spinner-border spinner-border-sm mx-2" role="status">
                </div>
            `;

            // Call the backend route
            fetch('{{ route('hls.videos.assign-video-to-module') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        _token: "{{ csrf_token() }}",
                        video_id: video.video_id,
                        video_title: video.title,
                        folder_id: existingVideosState.currentFolderId,
                        model_type: modelType,
                        model_id: modelId
                    })
                })
                .then(async response => {
                    window.location.reload();
                })
                .catch(error => {
                    console.log(error);
                    // Re-enable button
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalBtnText;
                });
        }

        // ========== دوال إطلاق الأحداث ==========

        function fireVideoStartedUploadingEvent(uploadData) {
            const files = uppy.getFiles();
            const eventData = {
                files: files.map(file => ({
                    name: file.name,
                    size: file.size,
                    type: file.type,
                    data: file.data
                })),
                uploadId: Date.now().toString(),
                timestamp: new Date().toISOString(),
                totalFiles: files.length,
                totalSize: files.reduce((total, file) => total + file.size, 0)
            };

            const event = new CustomEvent('videoStartedUploading', {
                detail: eventData,
                bubbles: true,
                cancelable: true
            });

            document.dispatchEvent(event);
        }

        function fireVideoUploadProgressEvent(file, progress) {
            const eventData = {
                file: {
                    id: file.id,
                    name: file.name,
                    size: file.size,
                    type: file.type
                },
                progress: progress,
                uploadId: Date.now().toString(),
                timestamp: new Date().toISOString()
            };

            const event = new CustomEvent('videoUploadProgress', {
                detail: eventData,
                bubbles: true,
                cancelable: true
            });

            document.dispatchEvent(event);
        }

        function fireVideoUploadCompleteEvent(file, response) {
            const eventData = {
                file: {
                    id: file.id,
                    name: file.name,
                    size: file.size,
                    type: file.type
                },
                response: response,
                videoId: response.body.video_id,
                uploadId: Date.now().toString(),
                timestamp: new Date().toISOString()
            };

            const event = new CustomEvent('videoUploadComplete', {
                detail: eventData,
                bubbles: true,
                cancelable: true
            });

            document.dispatchEvent(event);
        }

        function fireVideoUploadErrorEvent(file, error, response) {
            const eventData = {
                file: {
                    id: file.id,
                    name: file.name,
                    size: file.size,
                    type: file.type
                },
                error: error,
                response: response,
                uploadId: Date.now().toString(),
                timestamp: new Date().toISOString()
            };

            const event = new CustomEvent('videoUploadError', {
                detail: eventData,
                bubbles: true,
                cancelable: true
            });

            document.dispatchEvent(event);
            console.error('videoUploadError event fired:', eventData);
        }

        // ========== الدوال الحالية ==========

        function refreshVideoViewContent() {
            var folderIdInput = document.getElementById("current_folder_id");

            if (videoId && !folderIdInput && !folderIdInput?.value) {
                var url = '{{ route('hls.videos.options', ':id') }}';
                url = url.replace(':id', videoId);
            } else {
                var url = '{{ route('hls.videos.options') }}';
            }

            $.ajax({
                url: url,
                method: 'GET',

                beforeSend: function() {
                    $("#video-options-card").html('');
                    $('#video_loader').show();
                },
                success: function(response) {
                    setVideoOptionCard(response);
                },
                error: function(xhr, status, error) {
                    toastr["error"]("Error loading video options");
                    $('#video_loader').hide();
                }
            });
        }

        function setVideoOptionCard(response) {
            $("#video-options-card").html(response.html);
            $('#video_loader').hide();
            if (response.build_uploader)
                setupVideoUpload();

            if (response.is_ready) {
                videoPlayerIoRun(response.video_source)
            }
        }

        function deleteVideo(url) {
            var _token = $('input[name=_token]').val();

            bootbox.confirm({
                message: 'هل انت متأكد من حذف الفيديو',
                buttons: {
                    confirm: {
                        label: '{{ __('Yes') }}',
                        className: 'btn-success'
                    },
                    cancel: {
                        label: '{{ __('No') }}',
                        className: 'btn-danger'
                    }
                },

                callback: function(result) {
                    if (result) {

                        $("#video-options-card").html('');
                        $('#video_loader').show();
                        $.ajax({
                            method: 'DELETE',
                            url: url,
                            data: {
                                _token: _token,
                                modelType: modelType,
                                modelId: modelId
                            },
                            success: function(response) {
                                setVideoOptionCard(response);
                                resetVideo();
                            },
                            error: function(msg) {
                                toastr["error"](msg[1]);
                            }
                        });
                    }
                }
            });
        }

        $(document).ready(function() {
            refreshVideoViewContent();
        })
    </script>
    @include('hls-videos::components._jsvideo')
@endpush
