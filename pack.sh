#!/bin/sh

folder=`dirname $0`
version=`grep "const VERSION" ${folder}/Actindo/Bootstrap.php | cut -d"'" -f2`
cd ${folder}

targetFile="actindo-shopware4-"${version}".zip"

if [ -f ${targetFile} ]; then
    echo "This version was already packed, forgot to bump version number in Actindo/Bootstrap.php?"
    exit 99
fi

cd ${folder}
zip -r ${targetFile} Actindo/
