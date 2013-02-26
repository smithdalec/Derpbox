Derpbox Installation
--------------------
- Built upon Symfony2, see Symfony's documentation below
    cd path/to/Derpbox
    cp app/config/parameters.yml.dist app/config/parameters.yml
- Edit app/config/parameters.yml to with your database credentials
- Install Composer (step 2 below)
    composer update
    composer install
- If stylesheets/javascript/images weren't handled by Composer, also run:
    app/console assets:install
- If mod_rewrite isn't enabled, use path/to/derpbox/app.php as the index file
- SQL structure for the MySQL database is in a dump file located in src/Smithdalec/DerpboxBundle/Resources/derpbox.sql with the structure for tables, and some predefined users in the users table.


Symfony 2 Installation
======================

1) Installing the Standard Edition
----------------------------------

As Symfony uses [Composer][2] to manage its dependencies, the recommended way
to create a new project is to use it.

If you don't have Composer yet, download it following the instructions on
http://getcomposer.org/ or just run the following command:

    curl -s https://getcomposer.org/installer | php

Then, use the `create-project` command to generate a new Symfony application:

    php composer.phar create-project symfony/framework-standard-edition path/to/install 2.1.x-dev

Composer will install Symfony and all its dependencies under the
`path/to/install` directory.

If you downloaded an archive "without vendors", you also need to install all
the necessary dependencies. Download composer (see above) and run the
following command:

    php composer.phar install

2) Checking your System Configuration
-------------------------------------

Before starting coding, make sure that your local system is properly
configured for Symfony.

Execute the `check.php` script from the command line:

    php app/check.php

Access the `config.php` script from a browser:

    http://localhost/path/to/symfony/app/web/config.php

If you get any warnings or recommendations, fix them before moving on.
