# CORE-POS Office
CORE-POS backend management and reporting suite

### Install
Prequisites:
* A webserver. Apache 2+ is recommended.
* PHP 5.5 or greater
* An SQL server. MySQL 5+ is recommended.

Install depedencies:
1. Copy composer.json.dist to composer.json
2. Run "composer install"
3. Include "fannie" folder somewhere on the webserver.
4. Rename fannie/config.php.dist to fannie/config.php.
   If the config.php.dist file is missing, just create a
   new config.php with opening and closing php tags.
5. Browse to fannie/install/
6. Follow instructions for making config.php writable by the
   webserver if needed.
7. Enter database connection credentials and click save
   to initialize the database.
