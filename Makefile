# Lệnh tắt cho môi trường Docker. Ví dụ: make artisan c="route:list"
.PHONY: up down build shell artisan composer test fresh logs assets assets-dev

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell:
	docker compose exec app bash

artisan:
	docker compose exec app php artisan $(c)

composer:
	docker compose exec app composer $(c)

test:
	docker compose exec app php artisan test $(c)

fresh:
	docker compose exec app php artisan migrate:fresh --seed

logs:
	docker compose logs -f app queue scheduler

# Asset website (Tailwind v4 + Vite) build trên máy host: cần Node 22+
assets:
	npm run build

assets-dev:
	npm run dev
