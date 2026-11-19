#!/bin/sh
# Usage : ./probe.sh "question" ["question suivante" ...]   (compile puis converse avec le moteur)
D=$(cd "$(dirname "$0")" && pwd); R=$D/../../..; OUT=${TMPDIR:-/tmp}/helpme_brain; JAR=${JSON_JAR:-$OUT/json.jar}
mkdir -p $OUT; [ -f $JAR ] || curl -sSfLo $JAR https://repo1.maven.org/maven2/org/json/json/20240303/json-20240303.jar
javac -encoding UTF-8 -cp $JAR -d $OUT $(ls $R/app_android/app/src/main/java/com/hightechniger/help_me/brain/*.java | grep -v BrainHolder) $D/*.java 2>&1 | grep -v JAVA_TOOL_OPTIONS
CLS=${CLS:-Probe}
LANG=C.UTF-8 LC_ALL=C.UTF-8 java -Dfile.encoding=UTF-8 -Dsun.stdout.encoding=UTF-8 -Dsun.jnu.encoding=UTF-8 -Dbrain=$R/app_android/app/src/main/assets/brain.json -Dcases=$D/${CASES:-cases.txt} -cp $JAR:$OUT $CLS "$@" 2>&1 | grep -v JAVA_TOOL_OPTIONS
