# Git extension

An easy to use self deploy tool to interact with github.com webhooks.

## Installation

In `config.local.neon`:

```yaml
extensions:
    git: Tulinkry\DI\GitExtension
```

## Configuration

```yaml
git:
    repositories:
        default:
            username: username
            repository: repository
            secret: abcd
            directory: %appDir%/download/
```

This will download the repository username/repository from github.com and unzip it to the directory `%appDir%/download`.


You can also specify directly `%appDir%/..` as it will actually deploy the current master as your application.
Use with care!

## Webhook

The application is ready on the `/git` path for incomming webhook call.

## Dependencies

Depends in zip extension for php.
