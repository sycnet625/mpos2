# Nginx deploy

Estas plantillas no se aplican solas con `git pull`. En cada host nuevo hay que copiar el vhost al directorio real de Nginx y recargar el servicio.

```bash
sudo cp /var/www/nginx/sites-available/palweb /etc/nginx/sites-available/palweb
sudo ln -sfn /etc/nginx/sites-available/palweb /etc/nginx/sites-enabled/palweb
sudo nginx -t
sudo systemctl reload nginx
```

Despues de aplicar, verificar que los alias legacy resuelven:

```bash
curl -I https://TU_HOST/pos
curl -I https://TU_HOST/pos/
curl -I https://TU_HOST/shop
curl -I https://TU_HOST/shop/
curl -I https://TU_HOST/products
curl -I https://TU_HOST/products/
curl -I https://TU_HOST/clock
curl -I https://TU_HOST/clock/
```

`/pos`, `/shop`, `/products` y `/clock` deben redirigir a la version con slash final. `/pos/` debe cargar `pos.php`, `/shop/` debe cargar `shop.php`, `/products/` debe cargar `products/index.php` y `/clock/` debe cargar `clock/index.php`.
