#!/bin/bash
# Kloxo CP installer entry point.
# Today: pre-flight only (kloxocp doctor --install). Apt repo / packages are not built.
# See docs/INSTALL_AND_UPDATE_DESIGN.md and README.md.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
exec php "$HERE/kloxo/bin/kloxocp" install "$@"
