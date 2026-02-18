.PHONY: setup php cs-fixer phpstan tests ci-local

setup:
	rm -f composer.lock
	docker-compose up --build -d php
	docker-compose exec php composer install

php:
	docker-compose exec php sh

cs-fixer:
	docker-compose exec php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes

phpstan:
	docker-compose exec php vendor/bin/phpstan analyse src tests --configuration=phpstan.neon --memory-limit=1G

tests:
	rm -rf var/cache/test
	mkdir -p var/cache/test
	docker-compose exec php vendor/bin/phpunit

ci-local:
	act -j build
