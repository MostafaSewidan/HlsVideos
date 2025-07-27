@push('hls-styles')
    <link rel="stylesheet" href="https://releases.transloadit.com/uppy/v3.18.0/uppy.css">
    <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
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
    </style>
@endpush

<div id="video-options-card"></div>
<div class="col-md-12 text-center" id="video_loader">
    <span class="loader"></span>
</div>


@push('hls-scripts')
    <script src="https://releases.transloadit.com/uppy/v3.18.0/uppy.min.js"></script>
    <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script>
        let uppy = null;

        let modelType = "{{ str_replace('\\', '\\\\', get_class($model)) }}";
        let modelId = "{{ $model->id }}";
        @if ($video?->id)
            let videoId = "{{ $video?->id }}";
        @else
            let videoId = null;
        @endif


        function setupVideoUpload() {
            uppy = new Uppy.Uppy({
                restrictions: {
                    maxNumberOfFiles: 1, // ✅ Allow only one file
                    maxFileSize: 1000000000, // 50MB
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
                    console.log(previewWrapper);

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
                    // 'GoogleDrive',
                    // 'Url',
                    // 'Dropbox', 'Instagram', 'OneDrive', 
                    //'Webcam', 'ScreenCapture', 'Url'
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

                if (sidebar && false) {
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = `
                    <div class="uppy-DashboardTab" role="presentation" data-uppy-acquirer-id="choose-from-existing">
                        <button 
                        type="button" 
                        class="uppy-u-reset uppy-c-btn uppy-DashboardTab-btn" 
                        role="tab" 
                        tabindex="0"
                        >
                        <div class="uppy-DashboardTab-inner">
                            <i class="fa fa-cloud" style="
                                font-size: 2rem;
                                color: #FF3D00;
                            "></i>
                        </div>
                        <div class="uppy-DashboardTab-name">الدروس السابقة</div>
                        </button>
                    </div>
                    `;

                    const tab = wrapper.firstElementChild;

                    sidebar.appendChild(tab);
                } else {
                    console.error('Uppy sidebar not found');
                }
            }, 500); // Allow dashboard to render

            // Set meta before upload
            uppy.setMeta({
                model_type: "{{ str_replace('\\', '\\\\', get_class($model)) }}",
                model_id: "{{ $model->id }}",
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
                allowMultipleUploads: false
                // - withCredentials: true (if you need to send credentials with requests)
            });

            uppy.on('upload-success', (file, response) => {
                videoId = response.body.video_id;
                setVideoOptionCard(response.body);
            });
        }

        function refreshVideoViewContent() {
            if (videoId) {
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
                            },
                            error: function(msg) {
                                toastr["error"](msg[1]);
                            }
                        });
                    }
                }
            });
        }

        function videoPlayerIoRun(source) {
            let video = document.getElementById('player');

            if (Hls.isSupported()) {
                const hls = new Hls();
                hls.loadSource(source);
                hls.attachMedia(video);
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = source;
            }

            const player = new Plyr(video);
        }
        $(document).ready(function() {
            refreshVideoViewContent();
        })
    </script>
@endpush
