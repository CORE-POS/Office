#!bin/sh

if [ ! -d "$1" -o ! -d "$2" ]; then
    echo "Usage: diff.sh [directory] [directory]"
    exit
fi

diff -r -b -B --exclude="config.php" \
    --exclude="*.png" --exclude="*.jpg" \
    --exclude="*.log" --exclude="*.bmp" \
    --exclude="*~" --exclude="MemcacheStorage*" \
    --exclude="workshop" --exclude="*.csv" \
    --exclude="special" --exclude="dist" \
    "$1" "$2"
