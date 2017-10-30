#!/usr/bin/expect -f
spawn git pull
expect "password"
send "mjbsc</>\r"
interact