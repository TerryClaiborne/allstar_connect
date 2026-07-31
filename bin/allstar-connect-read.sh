#!/usr/bin/env bash
set -euo pipefail
ASTERISK=/usr/sbin/asterisk
[[ -x "$ASTERISK" ]] || { echo "Asterisk is unavailable." >&2; exit 1; }
case "${1:-}" in
    iax-channels)
        [[ $# -eq 1 ]] || exit 2
        exec "$ASTERISK" -rx "iax2 show channels"
        ;;
    core-channels)
        [[ $# -eq 1 ]] || exit 2
        exec "$ASTERISK" -rx "core show channels concise"
        ;;
    echolink-name)
        [[ $# -eq 2 && "$2" =~ ^[0-9]{1,6}$ ]] || exit 2
        exec "$ASTERISK" -rx "echolink dbget nodename $2"
        ;;
    rpt-lstats)
        [[ $# -eq 2 && "$2" =~ ^[0-9]{4,6}$ ]] || exit 2
        exec "$ASTERISK" -rx "rpt lstats $2"
        ;;
    *)
        echo "Unsupported read action." >&2
        exit 2
        ;;
esac
