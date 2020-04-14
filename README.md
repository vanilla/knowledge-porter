# knowledge-porter
A command line porter for Vanilla Knowledge.

Example:
```
./bin/knowledge-porter import --config="./customer1.zendesk.json"
```
Where `./customer1.zendesk.json` is relative path to your configuration file.

Configuration file contains set of feature flags for porter to configure source and destination (data & api).
Example:
```
{
    "source": {
        "type": "zendesk",
        "prefix": "{PREFIX}", // prefix to set foreign id on destination records
        "baseUrl": "{ZENDESK_API_PATH}", // eg: https://customer1.zendesk.com/api/v2/
        "token": "{EMAIL_TOKEN}", // eg: dev@mail.com/token:xxQWERTYptodnoL
        "targetDomain": "{NEW_DOMAIN_PATH}", // eg: https://dev.vanilla.localhost/kb/articles/aliases
        "sourceDomain": "{OLD_DOMAIN_PATH}", // eg: https://customer1.zendesk.com
        "perPage": 2,
        "pageFrom": 1,
        "pageTo": 100,
        "import": {
            "categories": true,
            "sections": true,
            "articles": true,
            "translations": true,
            "helpful": true
        },
        "api": {
            "cache": true,
            "log": true
        }
    },
    "destination": {
        "type": "vanilla",
        "path": "./export",
        "baseUrl": "{VANILLA_API_DESTINATION_INSTANCE}", // ex: https://dev.vanilla.localhost
        "token": "va.{VANILLA_API_TOKEN}",
        "update": "onChange",
         "api": {
            "cache": false,
            "log": true
        }
        // by default we don't want KB to be patched after 1st sync 
        // that will allow to avoid kb-url-slug update if edited on vanilla side
        "patchKnowledgeBase": false, 
        "syncUserByEmailOnly": false
    }
}
```

### Users and Authors

When porter import articles it is searching for existing user by `email` and uses it if found.

If user is not found by email porter checks configuration feature flag `syncUserByEmailOnly` and if that flag is false (dafault) search user by `name`.

If user still can not be found porter will create new user.

### Imoprt from multiple domains

In case we need to import data from different domains and they all could be reached using same api credentials/token
there is another tool `mulit-import.sh`.

This script  get one argument which is folder name with special configuration file.
For example `gink`.

That folder should have 2 files inside: `template.json` and `domains`.
![Screen Shot 2020-03-25 at 5 22 12 PM](https://user-images.githubusercontent.com/15682507/77586847-3842d600-6ebd-11ea-8d18-0c27dbb8bfef.png)

`template.json` is regular config json file for this porter. but with 2 special values `prefix` and `source-domain` :
```
{
 "source": {
        "type": "zendesk",
        "foreignIDPrefix": "{prefix}",
        "domain": "{source-domain}",
        ...
  },
 "destination": {
        "type": "vanilla",
        ...
    }
}
```
and `domains` file has very flat structure `domain.com=prefix` like:
```
diamond.gink.com=diamond
digger.gink.com=digger
betty.gink.com=betty
```
This bash script will:
- read `domains` and loop trough
- get each domain and prefix value
- copy `template.json` to `/conf` subfolder with new name. Eg: `diamond.gink.com.json`
- substitute `prefix` and `source-domain` in that new configuration file
- run `knowledge-porter` command in background with this per domain prepared config

Example:
```
./bin/multi-import.sh gink
```

This `multi-import` script starts many php processes in background (one process per domain). 

We can check progress using various log files created by this tool in targeted `{folder}/log`.

There is one special log file `{folder}/log/import.log`.

It has PIDs of running php processes in case developer need to stop them or investigate any incident.
