#!/usr/bin/env bash
set -euo pipefail

ASTERISK=/usr/sbin/asterisk
[[ -x "$ASTERISK" ]] || { echo "Asterisk is unavailable." >&2; exit 1; }

valid_node() { [[ "${1:-}" =~ ^[0-9]{1,7}$ ]]; }
valid_client() { [[ "${1:-}" =~ ^[A-Za-z0-9_.:@-]{1,96}$ ]]; }
valid_channel() { [[ "${1:-}" =~ ^IAX2/[A-Za-z0-9_.:@-]{1,96}$ ]]; }

case "${1:-}" in
    rpt-fun)
        [[ $# -eq 3 ]] || exit 2
        valid_node "$2" || exit 2
        [[ "$3" =~ ^[0-9*#]{1,14}$ ]] || exit 2
        exec "$ASTERISK" -rx "rpt fun $2 $3"
        ;;
    rpt-ilink)
        [[ $# -eq 4 ]] || exit 2
        valid_node "$2" || exit 2
        [[ "$3" =~ ^(1|3|8|11)$ ]] || exit 2
        if [[ "$3" == "11" ]]; then
            valid_client "$4" || exit 2
        else
            valid_node "$4" || exit 2
        fi
        exec "$ASTERISK" -rx "rpt cmd $2 ilink $3 $4"
        ;;
    rpt-lstats)
        [[ $# -eq 2 ]] || exit 2
        valid_node "$2" || exit 2
        exec "$ASTERISK" -rx "rpt lstats $2"
        ;;
    rpt-nodes)
        [[ $# -eq 2 ]] || exit 2
        valid_node "$2" || exit 2
        exec "$ASTERISK" -rx "rpt nodes $2"
        ;;
    core-channels)
        [[ $# -eq 1 ]] || exit 2
        exec "$ASTERISK" -rx "core show channels concise"
        ;;
    channel-hangup)
        [[ $# -eq 2 ]] || exit 2
        valid_channel "$2" || exit 2
        exec "$ASTERISK" -rx "channel request hangup $2"
        ;;
    echolink-module-show)
        [[ $# -eq 1 ]] || exit 2
        exec "$ASTERISK" -rx "module show like echolink"
        ;;
    echolink-module-load)
        [[ $# -eq 1 ]] || exit 2
        exec "$ASTERISK" -rx "module load chan_echolink.so"
        ;;
    echolink-module-unload)
        [[ $# -eq 1 ]] || exit 2
        exec "$ASTERISK" -rx "module unload chan_echolink.so"
        ;;
    echolink-dbget)
        [[ $# -eq 2 ]] || exit 2
        [[ "$2" =~ ^[0-9]{1,6}$ ]] || exit 2
        exec "$ASTERISK" -rx "echolink dbget nodename $2"
        ;;
    *)
        echo "Unsupported control action." >&2
        exit 2
        ;;
esac
