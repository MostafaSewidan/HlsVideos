@push('hls-styles')
    <style>
        .hls-folder-picker-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .hls-folder-picker-overlay.active {
            display: flex;
        }

        .hls-folder-picker-dialog {
            background: #fff;
            border-radius: 8px;
            width: 100%;
            max-width: 560px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .hls-folder-picker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 2px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }

        .hls-folder-picker-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        #hls-folder-picker-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 12px 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .hls-folder-picker-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
            min-height: 180px;
        }

        .hls-folder-picker-footer {
            padding: 16px 20px;
            border-top: 2px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .hls-folder-picker-overlay .existing-folder-item.selected {
            border-color: #4361ee;
            background: #e7f1ff;
        }
    </style>
@endpush

<div class="hls-folder-picker-overlay" id="hls-folder-picker-overlay">
    <div class="hls-folder-picker-dialog">
        <div class="hls-folder-picker-header">
            <h3>اختر مجلد لحفظ الفيديو في مكتبة الفيديوهات</h3>
            <button type="button" class="close-existing-panel" id="hls-folder-picker-close">&times;</button>
        </div>

        <div id="hls-folder-picker-breadcrumb"></div>

        <div class="hls-folder-picker-body" id="hls-folder-picker-body"></div>

        <div class="hls-folder-picker-footer">
            <button type="button" class="btn btn-secondary" id="hls-folder-picker-cancel">إلغاء</button>
            <button type="button" class="btn-select-video" id="hls-folder-picker-confirm">
                <i class="fas fa-check mx-2"></i>
                متابعة
            </button>
        </div>
    </div>
</div>

@push('hls-scripts')
    <script>
        let hlsFolderPickerState = {
            currentFolder: null,
            folders: [],
            breadcrumb: [],
            selectedFolder: null,
            isSharedMode: false,
            pendingTrigger: null,
        };

        function getMyDeviceTrigger(target) {
            if (!target || !target.closest) return null;

            const dropArea = document.getElementById('drag-drop-area');
            if (!dropArea) return null;

            const trigger = target.closest(
                '[data-uppy-acquirer-id="MyDevice"] button, .uppy-Dashboard-browse, .uppy-Dashboard-browseBtn'
            );

            return trigger && dropArea.contains(trigger) ? trigger : null;
        }

        document.addEventListener('click', function(event) {
            const trigger = getMyDeviceTrigger(event.target);
            if (!trigger || document.getElementById('current_folder_id')) return;

            event.preventDefault();
            event.stopPropagation();
            if (event.stopImmediatePropagation) {
                event.stopImmediatePropagation();
            }

            openHlsFolderPicker(trigger);
        }, true);

        function openHlsFolderPicker(trigger) {
            const overlay = document.getElementById('hls-folder-picker-overlay');
            if (!overlay) return;

            hlsFolderPickerState.pendingTrigger = trigger || null;
            hlsFolderPickerState.selectedFolder = null;
            overlay.classList.add('active');

            document.addEventListener('keydown', handleHlsFolderPickerKeyboard);
            loadHlsFolderPickerFolders();
        }

        function closeHlsFolderPicker() {
            const overlay = document.getElementById('hls-folder-picker-overlay');
            if (!overlay) return;

            overlay.classList.remove('active');
            hlsFolderPickerState.pendingTrigger = null;
            hlsFolderPickerState.selectedFolder = null;
            hlsFolderPickerState.folders = [];
            hlsFolderPickerState.breadcrumb = [];

            document.removeEventListener('keydown', handleHlsFolderPickerKeyboard);
        }

        function handleHlsFolderPickerKeyboard(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeHlsFolderPicker();
            }
        }

        function loadHlsFolderPickerFolders(folderId = null) {
            const body = document.getElementById('hls-folder-picker-body');
            if (!body) return;

            body.innerHTML = `
                <div class="existing-videos-loading">
                    <div class="spinner"></div>
                    <p style="margin-top: 16px;">جاري التحميل...</p>
                </div>
            `;

            const baseUrl = @json(route('hls.folders.list-folders'));
            const apiUrl = folderId ? `${baseUrl}?id=${folderId}` : baseUrl;

            fetch(apiUrl, {
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Failed to load folders');
                    }

                    const responseData = data.data || data;

                    hlsFolderPickerState.isSharedMode = responseData.is_shared_mode;
                    hlsFolderPickerState.currentFolder = responseData.folder || null;
                    hlsFolderPickerState.folders = responseData.folders || [];
                    hlsFolderPickerState.selectedFolder = hlsFolderPickerState.currentFolder;

                    hlsFolderPickerState.breadcrumb = hlsFolderPickerState.isSharedMode
                        ? [{ id: null, title: 'الرئيسية' }, ...(responseData.breadcrumb || [])]
                        : (responseData.breadcrumb || []);

                    renderHlsFolderPicker();
                })
                .catch(error => {
                    console.error('Error loading folders:', error);
                    body.innerHTML = `
                        <div class="empty-existing-videos">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>فشل تحميل المجلدات</p>
                            <button type="button" class="btn btn-primary mt-3" onclick="loadHlsFolderPickerFolders()">
                                حاول مرة أخرى
                            </button>
                        </div>
                    `;
                });
        }

        function renderHlsFolderPicker() {
            renderHlsFolderPickerBreadcrumb();

            const body = document.getElementById('hls-folder-picker-body');
            const confirmBtn = document.getElementById('hls-folder-picker-confirm');
            if (!body) return;

            const current = hlsFolderPickerState.currentFolder;
            const selectedId = hlsFolderPickerState.selectedFolder?.id ?? null;
            let html = '';

            if (current) {
                html += `
                    <div class="existing-folder-item ${selectedId === current.id ? 'selected' : ''}"
                         data-folder-id="${current.id}"
                         onclick="selectHlsFolderPickerFolder(${current.id})">
                        <div class="existing-folder-icon">
                            <i class="fas fa-folder-open"></i>
                        </div>
                        <div class="existing-folder-info">
                            <div class="existing-folder-title">${current.title}</div>
                            <div class="existing-folder-meta">مجلد الفيديوهات الحالي</div>
                        </div>
                        <div>
                            <i class="fas fa-check-circle"
                               style="font-size: 22px; color: ${selectedId === current.id ? '#4361ee' : '#e9ecef'};"></i>
                        </div>
                    </div>
                `;
            }

            hlsFolderPickerState.folders.forEach(folder => {
                html += `
                    <div class="existing-folder-item ${selectedId === folder.id ? 'selected' : ''}"
                         data-folder-id="${folder.id}"
                         onclick="selectHlsFolderPickerFolder(${folder.id})"
                         ondblclick="navigateHlsFolderPicker(${folder.id})">
                        <div class="existing-folder-icon">
                            <i class="fas fa-folder"></i>
                        </div>
                        <div class="existing-folder-info">
                            <div class="existing-folder-title">${folder.title}</div>
                            <div class="existing-folder-meta">
                                <i class="fas fa-folder mx-1"></i>مجلد
                                ${folder.created_at ? ` • ${folder.created_at}` : ''}
                            </div>
                        </div>
                        <div onclick="event.stopPropagation(); navigateHlsFolderPicker(${folder.id});"
                             style="padding: 8px;">
                            <i class="fas fa-chevron-left" style="font-size: 15px; color: #6c757d;"></i>
                        </div>
                    </div>
                `;
            });

            if (!html) {
                html = `
                    <div class="empty-existing-videos">
                        <i class="fas fa-folder-open"></i>
                        <p>هذا المجلد فارغ</p>
                    </div>
                `;
            }

            body.innerHTML = html;
            if (confirmBtn) {
                confirmBtn.disabled = !hlsFolderPickerState.selectedFolder;
            }
        }

        function renderHlsFolderPickerBreadcrumb() {
            const container = document.getElementById('hls-folder-picker-breadcrumb');
            if (!container) return;

            const breadcrumb = hlsFolderPickerState.breadcrumb || [];
            if (breadcrumb.length === 0) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'flex';
            container.innerHTML = '';

            breadcrumb.forEach((item, index) => {
                const isLast = index === breadcrumb.length - 1;
                const crumb = document.createElement('div');
                crumb.className = `existing-breadcrumb-item ${isLast ? 'current' : ''}`;
                crumb.innerHTML = `
                    <i class="fas fa-folder${isLast ? '-open' : ''}"></i>
                    <span>${item.title}</span>
                `;

                if (!isLast) {
                    crumb.addEventListener('click', () => navigateHlsFolderPicker(item.id ?? null));
                }

                container.appendChild(crumb);

                if (!isLast) {
                    const separator = document.createElement('div');
                    separator.className = 'existing-breadcrumb-separator';
                    separator.innerHTML = '<i class="fas fa-chevron-left"></i>';
                    container.appendChild(separator);
                }
            });
        }

        function navigateHlsFolderPicker(folderId) {
            loadHlsFolderPickerFolders(folderId);
        }

        function selectHlsFolderPickerFolder(folderId) {
            const current = hlsFolderPickerState.currentFolder;

            hlsFolderPickerState.selectedFolder = current && current.id === folderId
                ? current
                : (hlsFolderPickerState.folders.find(folder => folder.id === folderId) || null);

            renderHlsFolderPicker();
        }

        function confirmHlsFolderPickerSelection() {
            const selected = hlsFolderPickerState.selectedFolder;
            if (!selected) return;

            const trigger = hlsFolderPickerState.pendingTrigger;

            applyHlsFolderPickerSelection(selected.id);
            closeHlsFolderPicker();

            if (trigger) {
                trigger.click();
            }
        }

        function applyHlsFolderPickerSelection(folderId) {
            let input = document.getElementById('current_folder_id');

            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.id = 'current_folder_id';
                input.dataset.hlsFolderPicker = '1';
                document.body.appendChild(input);
            }

            input.value = folderId ?? '';

            if (uppy) {
                uppy.setMeta({ folder_id: folderId });
            }
        }

        function setupHlsFolderPicker() {
            const closeBtn = document.getElementById('hls-folder-picker-close');
            const cancelBtn = document.getElementById('hls-folder-picker-cancel');
            const confirmBtn = document.getElementById('hls-folder-picker-confirm');
            const overlay = document.getElementById('hls-folder-picker-overlay');

            closeBtn?.addEventListener('click', closeHlsFolderPicker);
            cancelBtn?.addEventListener('click', closeHlsFolderPicker);
            confirmBtn?.addEventListener('click', confirmHlsFolderPickerSelection);

            overlay?.addEventListener('click', function(event) {
                if (event.target === overlay) {
                    closeHlsFolderPicker();
                }
            });
        }

        $(document).ready(setupHlsFolderPicker);
    </script>
@endpush
