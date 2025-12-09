#!/bin/bash
set -e

echo "=== Pure TrueAsync PHP Server + MySQL 8.0 ==="
echo "PHP Version: $(php -v | head -n1)"
echo "MySQL Version: $(mysqld --version)"
echo ""

# Initialize MySQL if needed
if [ ! -d "/var/lib/mysql/mysql" ]; then
  echo "Initializing MySQL database..."
  mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
fi

# Start MySQL
echo "Starting MySQL..."
mysqld --user=mysql --datadir=/var/lib/mysql --socket=/var/run/mysqld/mysqld.sock &
MYSQL_PID=$!

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
for i in {1..30}; do
  if mysqladmin ping -h localhost --silent 2>/dev/null; then
    echo "MySQL is ready"
    break
  fi
  sleep 1
done

# Configure MySQL (set root password, create database and user)
if [ -n "$MYSQL_ROOT_PASSWORD" ]; then
  mysql -u root <<-EOSQL
    ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}';
    CREATE DATABASE IF NOT EXISTS ${MYSQL_DATABASE};
    CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_PASSWORD}';
    CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED WITH mysql_native_password BY '${MYSQL_PASSWORD}';
    GRANT ALL PRIVILEGES ON ${MYSQL_DATABASE}.* TO '${MYSQL_USER}'@'localhost';
    GRANT ALL PRIVILEGES ON ${MYSQL_DATABASE}.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
  echo "MySQL configured: database=${MYSQL_DATABASE}, user=${MYSQL_USER}"
fi

# Import database dump if exists and database is empty
DB_DUMP="/app/www/db.sql"
if [ -f "$DB_DUMP" ]; then
  echo "Found database dump at $DB_DUMP"
  TABLE_COUNT=$(mysql -u root -p${MYSQL_ROOT_PASSWORD} ${MYSQL_DATABASE} -e "SHOW TABLES;" 2>/dev/null | wc -l)
  if [ "$TABLE_COUNT" -le 1 ]; then
    echo "Importing database dump..."
    mysql -u root -p${MYSQL_ROOT_PASSWORD} ${MYSQL_DATABASE} < "$DB_DUMP"
    echo "Database dump imported successfully"
  else
    echo "Database already contains tables, skipping import"
  fi
else
  echo "No database dump found at $DB_DUMP"
fi

echo "Web Root: /app/www"
echo ""

# Copy server.php from templates if not exists
if [ ! -f "/app/www/server.php" ]; then
  echo "Copying server.php from templates..."
  cp /app/templates/server.php /app/www/server.php
fi

# Copy wp-loader.php from templates if not exists
if [ ! -f "/app/www/wp-loader.php" ]; then
  echo "Copying wp-loader.php from templates..."
  cp /app/templates/wp-loader.php /app/www/wp-loader.php
fi

# Verify server.php exists
if [ ! -f "/app/www/server.php" ]; then
  echo "ERROR: server.php not found at /app/www/"
  exit 1
fi

# Start PHP server
echo "Starting TrueAsync PHP Server..."
cd /app/www
php server.php 0.0.0.0 8080 &
PHP_PID=$!

# Wait a bit for server to start
sleep 2

echo ""
echo "========================================"
echo "Services are ready!"
echo "PHP Server: http://0.0.0.0:8080"
echo "MySQL: localhost:3306"
echo "  - Database: ${MYSQL_DATABASE}"
echo "  - User: ${MYSQL_USER}"
echo "========================================"
echo ""

# Wait for both processes
wait -n $PHP_PID $MYSQL_PID
exit $?
