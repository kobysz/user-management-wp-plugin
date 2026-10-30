#!/bin/sh
# Bramka wersji minimalnej PHP: sprawdza skladnie parserem kontenera 8.1, czyli tym,
# na ktorym stoi portal. Lapie i gramatyke, i bledy kompilacji - PHPStan lapie tylko
# gramatyke (wyrazenie stale czytajace enum przechodzi u niego zielono). Zob. Plan 9, P2-P3.
#
# Pusty zbior plikow jest bledem, nie sukcesem. Bez tego warunku bramka przechodzila
# na zielono, gdy find nie znalazl niczego (zly --env-cwd, przeniesiony katalog, literowka
# w sciezce): petla nie wykonywala sie ani raz i skrypt konczyl zerem. To ta sama klasa
# porazki, ktora Plan 9 nazwal dla pustej suity PHPUnit.
TARGETS="src portal-users.php uninstall.php"

for target in $TARGETS; do
	if [ ! -e "$target" ]; then
		echo "Bramka skladni: nie ma czego sprawdzac - brak '$target' w $( pwd )" >&2
		exit 1
	fi
done

files=$( find $TARGETS -name '*.php' ) || exit 1
if [ -z "$files" ]; then
	echo "Bramka skladni: find nie zwrocil ani jednego pliku PHP w $( pwd )" >&2
	exit 1
fi

count=0
fail=0
for f in $files; do
	count=$(( count + 1 ))
	if ! php -l "$f" > /dev/null 2>&1; then
		php -l "$f"
		fail=1
	fi
done

echo "Bramka skladni: sprawdzonych plikow $count"
exit "$fail"
