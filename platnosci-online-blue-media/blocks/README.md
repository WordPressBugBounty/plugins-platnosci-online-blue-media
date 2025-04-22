# Instrukcja dla kompilacji modułu reactowego (edytor blokowy)

Komendy uruchamiamy z poziomu katalogu `blocks`.


## Instalacja webpacka

1. Przełączanie na odpowiednią wersję nvm:

```
nvm use
```

lub w przypadku Windows:

```
nvm use `cat .nvmrc`
```

2. Instalacja webpacka, pojawi się katalog `node_modules` w folderze `blocks`:

```
npm install
```


## Kompilacja:

1. Przełączanie na odpowiednią wersję nvm:

```
nvm use
```

2. Kompilacja

```
npm run build
```


## Dodatkowe komendy

### Aktualizacja pakietów webpacka:

```
npm run packages-update
```