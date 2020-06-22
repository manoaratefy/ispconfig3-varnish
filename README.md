# README
This ISPConfig plugin is built for those who wants setup Varnish Cache in ISPConfig. We use Varnish for cache, Apache for the backend, and we added NGINX for SSL termination.

So, we can have two scenario :

 - Without SSL: `Visitor > Varnish > Apache`
 - With SSL: `Visitor > NGINX > Varnish > Apache`

I've done test with the following configuration:
- Debian 9 x64
- Apache 2.4.25
- NGINX 1.14.2
- Varnish 6.0.2

This should work fine with Ubuntu, and may requires small adjustments to work well on CentOS & RHEL-based distributions.
# Installation steps:
Install some required software for Varnish and NGINX repos:
`apt-get install debian-archive-keyring curl gnupg apt-transport-https gnupg2 ca-certificates lsb-release`

## Install Varnish repo

    curl -L https://packagecloud.io/varnishcache/varnish60lts/gpgkey | apt-key add -

Create a text file called `/etc/apt/sources.list.d/varnishcache_varnish60lts.list`:

    nano /etc/apt/sources.list.d/varnishcache_varnish60lts.list

Put this on:

    deb https://packagecloud.io/varnishcache/varnish60lts/debian/ stretch main
    deb-src https://packagecloud.io/varnishcache/varnish60lts/debian/ stretch main
Save and exit `nano` (`Ctrl+X`, `Y`, `Enter`).
## Install NGINX repo

    echo "deb http://nginx.org/packages/debian `lsb_release -cs` nginx" \
    | tee /etc/apt/sources.list.d/nginx.list
    curl -fsSL https://nginx.org/keys/nginx_signing.key | apt-key add -
## Install Varnish and NGINX    

    apt-get update
    apt-get install nginx varnish -y

## Install Git (if not installed)

    apt-get install git -y

Clone the repo:

    git clone https://github.com/manoaratefy/ispconfig3-varnish.git

Change Apache2 ports:

    sed -i 's/Listen 80/Listen 6080/g' /etc/apache2/ports.conf
    sed -i 's/Listen 443/Listen 6443/g' /etc/apache2/ports.conf

Move all files to its place:

    cd ispconfig3-varnish
    cp -R etc/* /etc/
    cp -R usr/* /usr/
    cp -R lib/* /lib/

Reload daemon:

    systemctl daemon-reload

Avoid NGINX to listen to port 80 and prepare folders:

    rm /etc/nginx/conf.d/default.conf
    mkdir /etc/nginx/sites-available
    mkdir /etc/nginx/sites-enabled

Enable the plugin:

    ln -s /usr/local/ispconfig/server/plugins-available/varnish_plugin.inc.php /usr/local/ispconfig/server/plugins-enabled/varnish_plugin.inc.php

Fix remote IP detection:

    a2enmod remoteip
    cp /etc/apache2/sites-available/ispconfig.conf /etc/apache2/sites-available/ispconfig.conf.old
    perl -pe 's/(\s*)LogFormat(\s+)"(.*)%h(.*)"(.*)combined_ispconfig/LogFormat "%v %a %l %u %t \\"%r\\" %>s %O \\"%{Referer}i\\" \\"%{User-Agent}i\\"" combined_ispconfig/g' /etc/apache2/sites-available/ispconfig.conf.old | tee /etc/apache2/sites-available/ispconfig.conf > /dev/null

Then, rebuild all vHost **BEFORE RESTARTING SERVICES** (in other case, Apache may not start then you'll not be able to open ISPConfig control panel).

    ISPConfig > Tools > Sync Tools > Resync > Check "Websites" > Start
After that, you can restart all services:

    systemctl restart apache2
    systemctl restart varnish
    systemctl restart nginx

# Notes

Apache ports: 6080 (non SSL) / 6443 (pseudo-SSL)
Varnish ports: 80 (non SSL) / 7443 (pseudo SSL)
NGINX ports: N/A (non SSL) / 443 (SSL)

The pseudo-SSL is a particular port used by Apache & Varnish to be a back-end for the NGINX SSL. The traffic itself is not SSL but the environment is configured to say to PHP scripts that we are on SSL connection (X-Forwarded-Proto & HTTPS environment variable).

# What needed to be improved?
I'm not a ISPConfig developer. I don't know if the way I do thing is good enough to have long-term compatibility with ISPConfig. I'm just making things working. So, I'm calling other developers to review my code and to adjust things that I do wrong.

Here is a short list of things I think I'm not doing great:

- **Full caching management interface on ISPConfig**
Admins and users may requires an interface to use Varnish correctly (advanced caching rules, flushing the cache, caching rules template, ...) It is relatively easy to implement it with an external software (means outside ISPConfig control panel) as the proposed Varnish configuration doesn't depend on any ISPConfig functionnality. Theorically, we can use any Varnish Control Panel without interference. But it would be great if someones found a way to integrate in under the ISPConfig interface itself.

There may be other improvements. Just open an issue/request a feature.

# Installation services
Do you need a sysadmin to install this module into your ISPConfig? I'm available for you. [Contact me](https://manoaratefy.hostibox.com/contact/).
# Donations
If my work was useful for your business, buy me a coffee:

- BTC: `32NriafwyTELpL7GgH8XoimsnA8Hh8U9FU`
- XMR: `833wfJerqTVb9fLhSgBNSQLQBSqsR4Tvr3sCE721JtD3bVpybqUWfHQUexcDYxJkX63rAZyPdqWDMP6BZULsL71yJN8xvTL`
- Other ways: [Contact me](https://manoaratefy.hostibox.com/contact/)

# Credits & Sponsors
I've found very useful information in the following URL:

 - https://github.com/Rackster/ispconfig3-nginx-reverse-proxy
 - https://www.howtoforge.com/community/

Of course, my code is proudly used on [professional web hosting](https://www.hostibox.com) that I would highly recommend.
