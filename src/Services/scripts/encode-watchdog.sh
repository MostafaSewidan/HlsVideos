#!/usr/bin/env bash
# Kills an ffmpeg HLS encode that has stopped writing output.
#
# Usage: encode-watchdog.sh <output dir> <stall seconds> <php pid> [check interval seconds]
#
# A healthy encode writes a .ts segment every few seconds. If ffmpeg has run
# for at least <stall seconds> and no segment in <output dir> was written in
# that time, it is stuck: it is killed and a marker file is left so PHP can
# report the stall. The watchdog exits when the PHP worker is gone, or when
# PHP stops it after the encode.

dir="${1%/}"
stall="$2"
php_pid="$3"
interval="${4:-30}"
marker="$dir/.encode-stalled"
stall_minutes=$(( (stall + 59) / 60 ))

first_seen=""

while sleep "$interval"; do
    kill -0 "$php_pid" 2>/dev/null || exit 0

    pids=$(pgrep -f -- "ffmpeg.*${dir}/")
    if [ -z "$pids" ]; then
        first_seen=""
        continue
    fi

    # Measured from when this watchdog first saw ffmpeg; it starts just before it.
    now=$(date +%s)
    [ -z "$first_seen" ] && first_seen=$now
    running=$(( now - first_seen ))
    [ "$running" -lt "$stall" ] && continue

    recent=$(find "$dir" -maxdepth 1 -type f -name '*.ts' -mmin "-${stall_minutes}" -print -quit 2>/dev/null)
    [ -n "$recent" ] && continue

    echo "no new segment for ${stall}s, ffmpeg running ${running}s" > "$marker"
    kill $pids 2>/dev/null
    sleep 10
    kill -9 $pids 2>/dev/null
    exit 0
done
