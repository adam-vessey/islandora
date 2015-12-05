#!/bin/bash


# Drush installation.
cd $HOME
composer self-update
if [ "$TRAVIS_PHP_VERSION" = '5.3.3' ] ; then
    # XXX: PHP 5.3.3 and drush 7.1.0 (the version it would otherwise download)
    # don't play nice: https://github.com/drush-ops/drush/issues/544
    composer global require 'drush/drush:^6.7'
else
    composer global require 'drush/drush'
fi
export PATH=$PATH:$HOME/.composer/vendor/bin
alias drush="drush --verbose"

# XXX: Coder is not compatible with PHP CS 2.x.
composer global require 'squizlabs/php_codesniffer:^1.4.6' 'sebastian/phpcpd=*'

# Database creation and priveleges.
mysql -u root -e 'create database drupal;'
mysql -u root -e "create database fedora;"
mysql -u root -e "GRANT ALL PRIVILEGES ON fedora.* To 'fedora'@'localhost' IDENTIFIED BY 'fedora';"
mysql -u root -e "GRANT ALL PRIVILEGES ON drupal.* To 'drupal'@'localhost' IDENTIFIED BY 'drupal';"

# Drupal installation.
drush dl --yes drupal-7
cd drupal-*
drush si --yes minimal --db-url=mysql://drupal:drupal@localhost/drupal
mysql -u root -D drupal -e "SELECT * FROM users;"

# Needs to make things from Composer be available (PHP CS, primarily)
sudo chmod a+w sites/default/settings.php
echo "include_once '$HOME/.composer/vendor/autoload.php';" >> sites/default/settings.php
sudo chmod a-w sites/default/settings.php

drush runserver --php-cgi=$HOME/.phpenv/shims/php-cgi localhost:8081 &>/tmp/drush_webserver.log &

# Add Islandora to the list of symlinked modules.
ln -s $ISLANDORA_DIR sites/all/modules/islandora

# Use our custom Travis test config for Simpletest.
mv sites/all/modules/islandora/tests/travis.test_config.ini sites/all/modules/islandora/tests/test_config.ini

# Grab Tuque.
mkdir sites/all/libraries
ln -s $HOME/tuque sites/all/libraries/tuque

# Grab and enable other modules.
drush dl --yes coder-7.x-2.5

drush dl --yes potx-7.x-1.0
drush en --yes coder_review
drush en --yes simpletest
drush en --yes potx
# The shebang in this file is a bogeyman that is haunting the web test cases.
rm /home/travis/.phpenv/rbenv.d/exec/hhvm-switcher.bash

# Islandora Tomcat installation.
cd $HOME
git clone git://github.com/Islandora/tuque.git
wget http://alpha.library.yorku.ca/islandora_tomcat.$FEDORA_VERSION.tar.gz
tar xf islandora_tomcat.$FEDORA_VERSION.tar.gz
cd islandora_tomcat
export CATALINA_HOME='.'
export JAVA_OPTS="-Xms1024m -Xmx1024m -XX:MaxPermSize=512m -XX:+CMSClassUnloadingEnabled -Djavax.net.ssl.trustStore=$CATALINA_HOME/fedora/server/truststore -Djavax.net.ssl.trustStorePassword=tomcat"
# TODO: roll a Fedora 3.8.1 islandora_tomcat that doesn't require a rebuild.
if [ $FEDORA_VERSION = "3.8.1" ]; then
  # Java 8, if needed.
  sudo add-apt-repository -y ppa:webupd8team/java
  sudo apt-get update
  sudo apt-get install -y oracle-java8-installer oracle-java8-set-default
  sudo update-java-alternatives -s java-8-oracle
  export JAVA_HOME=/usr/lib/jvm/java-8-oracle

  export FEDORA_HOME=fedora
  ./fedora/server/bin/fedora-rebuild.sh -r org.fcrepo.server.utilities.rebuild.SQLRebuilder
fi
./bin/startup.sh
sleep 20

cd $HOME/drupal-7*
drush --user=1 en --yes islandora
drush cc all
drush core-status

# Because we can't add to the PATH here and this file is used in many repos,
# let's just throw symlinks into a directory already on the PATH.
echo linking && find $HOME/.composer/vendor/bin -executable \! -type d -exec sudo ln -s {}  /usr/local/bin/ \;

