# Office Service (SIKD) 

<a href="https://codeclimate.com/github/jabardigitalservice/office-services/maintainability"><img src="https://api.codeclimate.com/v1/badges/888efd380ccef5a509cd/maintainability" /></a>
## Overview
This service is used by [Office Mobile (Flutter)](https://github.com/jabardigitalservice/office-mobile).

## Stack Architechture
1. PHP 8, Laravel
2. MariaDB 5.5
3. GraphQL

## Local development quickstart
Clone the repository
```
$ git clone git@github.com:jabardigitalservice/office-services.git
```

Enter into the `src` directory
```
$ cd src
```
Copy the config file and adjust the needs
```
$ copy .env-example .env
```
Generate the APP_KEY
```
$ php artisan key:generate
```
App dependencies using composer
```
$ composer install
```
DB migration
```
$ php artisan migrate
```

Run the local server:
```
$ php artisan serve
```

Having fun with the playgrond:
```
Open on the browser: {APP_URL}/graphql-playground
```

## Local development with docker

after cloning this repo, run this commands:

```bash
$ cd office-services
$ cp ./src/.env.example ./src/.env
$ docker compose up
# here the server should already run, but we still need to config our API_KEY

# in another terminal, we generate our API_KEY then copy it to our .env file
$ docker compose exec app php artisan key:generate --show

# while at it, we could also import our db and run our migration.
# before that, make sure we already prepare our dumped db file in our 
# 'office-services' folder. here its assumed that the dump file would be
# named 'dump.sql'.
$ docker compose exec database sh
# this dump operation require 'root' user access
> mysql -u root --password sikdweb sikdweb < /data/dump.sql
# use 'ctrl+d' to get out from mysql console & docker shell

# here we run our migrations
$ docker compose exec app php artisan migrate --force

# after that we stop and re-run our docker compose instance
$ docker compose down
$ docker compose up

# our server should ready to be accessed at http://localhost:8000
```

### Code Style Checking
```
$ ./vendor/bin/phpcs
```

### Unit & Feature Testing
```
$ ./vendor/bin/phpunit
```
