#!/bin/bash
dir=$@
mkdir -p $dir/log
mkdir -p $dir/conf
echo $(date -u) "Import job started." >> $dir/log/import.log
declare -a domains
while IFS== read -r domain prefix; do
    config=$dir/conf/$domain.json
    log=$dir/log/$domain.log
    cp $dir/template.json $config
    sed -i '' "s/{source-domain}/$domain/g" "$config"
    sed -i '' "s/{prefix}/$prefix/g" "$config"
   ./bin/knowledge-porter import --config=$config >> $log &
   echo PID:$! $config $log >> $dir/log/import.log
done < $dir/domains
