# MongoDB storage and database adapters for Imbo

[![CI](https://github.com/imbo/imbo-mongodb-adapters/workflows/CI/badge.svg)](https://github.com/imbo/imbo-mongodb-adapters/actions?query=workflow%3ACI)

[MongoDB](https://www.mongodb.com/) storage and database adapters for [Imbo](https://imbo.io).

## Installation

    composer require imbo/imbo-mongodb-adapters

## Usage

This package provides both storage and database adapters for Imbo, leveraging [GridFS](https://docs.mongodb.com/manual/core/gridfs/) and MongoDB.

## Running integration tests

If you want to run the integration tests you will need a running MongoDB service. The repo contains a simple configuration file for [Docker Compose](https://docs.docker.com/compose/) that you can use to quickly run a MongoDB instance.

If you wish to use this, run the following command to start up the service after you have cloned the repo:

```
docker-compose up -d
```

After the service is running you can execute all tests by simply running PHPUnit:

```
composer run test # or ./vendor/bin/phpunit
```

## License

MIT, see [LICENSE](LICENSE).