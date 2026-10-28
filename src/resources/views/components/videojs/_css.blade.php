<link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/@videojs/cdn@{{ config('hls-videos.videojs.version') }}/global.css">
<style>
    html,
    body {
        margin: 0;
        padding: 0;
    }

    /* Same sizes as the Plyr container, so the app's layout doesn't move. */
    .hls-vjs-container {
        position: relative;
        width: 100%;
        height: 100%;
        aspect-ratio: 10 / 6;
        background: #000;
        overflow: hidden;
    }

    @media (max-width: 991px) {
        .hls-vjs-container {
            aspect-ratio: 13 / 9;
        }
    }

    @media (max-width: 576px) {
        .hls-vjs-container {
            aspect-ratio: 7 / 10;
        }
    }

    /* Public styling settings of the packaged skin (see Video.js "Customize skins"). */
    .hls-vjs-skin {
        display: block;
        width: 100%;
        height: 100%;
        --media-accent-color: {{ config('hls-videos.videojs.accent_color', '#ffc629') }};
        --media-accent-text-color: #000;
        --media-border-radius: 0;
        --media-border-color: transparent;
        --media-object-fit: contain;
    }

    /* The skin's controls live in its shadow root, so hiding buttons (PiP, cast,
       fullscreen when off) is done by the script in videojs/_js. */
</style>
