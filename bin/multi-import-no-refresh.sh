#!/bin/bash
dir=$@
mkdir -p $dir/log
mkdir -p $dir/conf
echo $(date -u) "Import job started." >> $dir/log/import.log
declare -a domains
while IFS== read -r domain prefix; do
    config=$dir/conf/$domain.json
    log=$dir/log/$domain.log
   ./bin/knowledge-porter import --config=$config >> $log &
   echo PID:$! $config $log >> $dir/log/import.log
done < $dir/domains
