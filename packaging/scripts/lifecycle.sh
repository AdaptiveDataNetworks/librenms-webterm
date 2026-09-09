# Shared helpers for the deb and rpm maintainer scripts.
#
# GoReleaser hands the SAME script to both formats, and they disagree about
# everything that matters here:
#
#   postinstall   deb: $1 = "configure", $2 = old version when upgrading
#                 rpm: $1 = 1 on install, >= 2 on upgrade
#   preremove     deb: $1 = "remove" | "purge" | "upgrade" | "deconfigure"
#                 rpm: $1 = 0 on final removal, >= 1 on upgrade
#
# Getting this wrong is not cosmetic. Before these helpers existed, preremove
# stopped AND disabled the service unconditionally -- and on an rpm upgrade the
# old package's %preun runs AFTER the new package's %post, so `dnf upgrade`
# left the gateway stopped and disabled. On Debian the ordering differs and the
# result was the same: prerm stopped it and nothing started it again.

webterm_is_upgrade_install() {
    case "${1:-}" in
        configure) [ -n "${2:-}" ] && return 0; return 1 ;;   # deb
        ''|*[!0-9]*) return 1 ;;                              # not an rpm count
        *) [ "$1" -ge 2 ] && return 0; return 1 ;;            # rpm
    esac
}

webterm_is_final_removal() {
    case "${1:-}" in
        remove|purge) return 0 ;;                             # deb, really going
        upgrade|deconfigure|failed-upgrade) return 1 ;;       # deb, staying
        ''|*[!0-9]*) return 0 ;;                              # unknown: assume removal
        *) [ "$1" -eq 0 ] && return 0; return 1 ;;            # rpm: 0 = last copy
    esac
}

webterm_have_systemd() {
    [ -d /run/systemd/system ]
}
