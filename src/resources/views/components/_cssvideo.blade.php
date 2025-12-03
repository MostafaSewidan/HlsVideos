<link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --color-primary: #032336bd;
        --color-secondary: #ffc629;
        --color-accent: #3bc9db;
        --color-dark-blue: #29425f;

        --video-bg: #000;
        --video-player-main: var(--color-secondary);
        --video-overlay-bg: rgba(0, 0, 0, 0.6);
        --video-shadow-medium: rgba(0, 0, 0, 0.4);
        --video-shadow-strong: rgba(0, 0, 0, 0.6);
    }

    html,
    body {
        margin: 0;
        padding: 0;
    }

    .video-container {
        position: relative;
        width: 100%;
        height: 100%;
        /* min-height: 200px;
        max-height: 100vh; */
        background-color: transparent;
        overflow: hidden;
        box-shadow: 0 8px 32px var(--video-shadow-medium);
        transition: box-shadow 0.3s ease, transform 0.3s ease;
        aspect-ratio: 10 / 6;

        --plyr-color-main: var(--video-player-main);
        --plyr-video-background: var(--video-bg);
    }

    .video-overlay-left {
        background: red;
        width: 43%;
        height: 80%;
        position: absolute;
        z-index: 10;
        top: 2%;
        left: 2%;
    }

    .video-overlay-right {
        background: red;
        width: 43%;
        height: 80%;
        position: absolute;
        z-index: 10;
        top: 2%;
        right: 2%;
    }

    @media (max-width: 991px) {
        .video-container {
            aspect-ratio: 13 / 9;
        }
    }

    @media (max-width: 576px) {
        .video-container {
            aspect-ratio: 7 / 10;
        }
    }


    .plyr__controls .plyr__controls__item:first-child {
        margin-left: auto !important;
        margin-right: 0 !important;
    }

    .plyr__controls button.plyr__controls__item {
        position: absolute !important;
        left: 2px;
    }

    .plyr__controls .plyr__controls__item:last-child {
        position: relative !important;
    }

    .plyr__progress__container {
        position: absolute;
        width: 98%;
        top: 10px;
    }


    /* .video-container video {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: fill !important;
        display: block;
    } */

    .video-loading-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        background-color: var(--video-overlay-bg);
        z-index: 10;
    }

    .video-loading-overlay .spinner {
        width: 44px;
        height: 44px;
        border: 3px solid rgba(255, 255, 255, 0.25);
        border-top-color: var(--color-primary);
        border-radius: 50%;
        animation: cd-spin 1s linear infinite;
    }

    @keyframes cd-spin {
        to {
            transform: rotate(360deg);
        }
    }

    .plyr {
        height: 100%;
    }

    .plyr__controls {
        padding-inline: 10px;
    }

    .plyr__control--overlaid {
        position: absolute !important;
        top: 50% !important;
        left: 50% !important;
        transform: translate(-50%, -50%) !important;
        margin: 0 !important;
        background: var(--color-primary) !important;
        color: #fff !important;
        border-radius: 50% !important;
    }

    .plyr__controls__item.plyr__progress__container {
        position: absolute;
        top: 16px;
        width: 98%;
    }

    .plyr__controls .plyr__control[data-plyr="rewind"] {
        position: absolute !important;
        left: 10px !important;
    }

    .plyr__controls .plyr__control[data-plyr="play"] {
        position: absolute !important;
        left: 50px !important;
    }

    .plyr__controls .plyr__control[data-plyr="fast-forward"] {
        position: absolute !important;
        left: 90px !important;
    }

    .plyr__controls .plyr__time--current {
        position: absolute !important;
        left: 135px !important;
        bottom: 12px !important;

    }

    .plyr__controls .plyr__time--duration {
        position: absolute !important;
        left: 178px !important;
        bottom: 12px !important;

    }

    .download-menu {
        position: absolute;
        bottom: 46px;
        right: 42px;
        padding: 10px;
        border-radius: 6px;
        z-index: 9999;
    }

    .download-quality {
        display: block;
        width: 100%;
        text-align: right;
        padding: 8px;
        background: none;
        border: none;
        cursor: pointer;
    }

    .download-quality:hover {
        background: #dadada;
        border-radius: 6px;
    }
    
    .plyr__menu__container{
        right: 39px;
    }

    @media (max-width: 600px) {
        .plyr__controls__item.plyr__time--current.plyr__time {
            bottom: 9px !important;

        }

        .plyr__controls__item.plyr__progress__container {

            top: 1px !important;
        }

        .plyr__controls .plyr__control[data-plyr="play"] {
            left: 33px !important;
        }

        .plyr__controls .plyr__control[data-plyr="fast-forward"] {
            left: 55px !important;
        }

        .plyr__controls .plyr__time--current {
            left: 85px !important;
        }

        .plyr__controls .plyr__time--duration {
            left: 130px !important;
        }
    }
</style>
