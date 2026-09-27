# Roadmap:

this are roadmap to restructure pkg source-code and make it more easy to digest cuz i also feel `nightmare` would come if we don't fix it, take a look for instance the fatest one `./stubs/SshtApiGwClient/console/SshtApiClientController.php`. and yep here we are..

## Plan:

### 1. make `./repository` dirs for handling data sources. 

( for now it is not necessary.. )

### 2. make `./services` dirs for handling logic.

implemented `services` on /extensions/* from `v0.3.30`

### 3. make `./builder` dirs for building context exp: `the payload` 

( not yet implemented: not necessary for now.. )

### 4. console command controller need to be as general as posible it's like `more stupid more nice to lookie` so it will easy to digest..

(implemented hardly on SshtApiClientController)

yet from now `v0.3.30` there are several console command on diver purpose/usage:
- PacsConsoleController
    - ( for handling pacs `orthanc` task )
- SshtApiClientController
    - ( of course for main purpose this briging sdk T_T ) 
- SshtApiClientTestingController 
    - ( for user manual dev testing `be carefull for now all still running in PROD` )
- SshtApiClientTestQueryController 
    - ( for testing query on query mapping `SshtApiQueryMapping.php` )

### 5. ...


###### **NB: will doit as soon as posible..*
