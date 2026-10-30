#!/bin/sh
# Bramka wersji minimalnej PHP: sprawdza skladnie parserem kontenera 8.1, czyli tym,
# na ktorym stoi portal. Lapie i gramatyke, i bledy kompilacji - PHPStan lapie tylko
# gramatyke (wyrazenie stale czytajace enum przechodzi u niego zielono). Zob. Plan 9, P2-P3.
fail=0
for f in $( find src portal-users.php uninstall.php -name '*.php' ); do
	if ! php -l "$f" > /dev/null 2>&1; then
		php -l "$f"
		fail=1
	fi
done
exit "$fail"
