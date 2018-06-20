This is a WordPress module intended to work with PHP 5.4.


## Introduction

The goal is that this module will be generally useful and will allow
many different LTI and WordPress integrations to build off of one LTI
plugin that is easily extensible and allows for custom institutional
or business rules. Note that this module doesn't actually handle processing 
incoming LTI requests. This module just does all of the housekeeping and
you will need an additional plugin to enable the functionality you need.
Please see the [candela-lti plugin](https://github.com/lumenlearning/candela/tree/master/www/192.168.33.10/wp-content/plugins/candela-lti) which is part of the candela stack for an example implementation.


## Requirements

This plugin requires the OAuth library. You can install this via PECL.
On most systems this can be achieved with

    sudo pecl install oauth

After installing via PECL you will need to restart Apache.
.

## Functionality

This module then tracks incoming LTI requests does all of the necessary validation and housekeeping including;

- Ensure that the payload and payload signatures match.
- Ensure the timestamp is within an acceptable range. Default window is the LTI recommended 90 minutes.
- Ensure that the provided nonce has not been used previously within the timestamp window to prevent replay attacks.
- Hand off execution at various points to external WordPress plugins implementing the appropriate actions.

## Documentation

After installing the module users can create LTI consumer posts which generate the LTI secret and key pair for use in the LTI consumer application.

## Developers

This module currently exposes the following three hooks that are all called in succession. The plan here is to allow multiple modules to respond to LTI launches. All of the following three hooks can be implemented in your module by running the following code during your plugin initialization.

    add_action( 'lti_launch', YOUR_FUNCTION_TO_LAUNCH );

Similarly you can do the same for the other two actions this plugin invokes.

### lti_setup

The intent of this hook is to do any necessary account creation, site creation or generally any type of content, user or permissions that need to be configured before any subsequent steps should happen.

### lti_pre

The intent of this hook is to do any necessary steps such as authenticating a user, switching a user to a different site/blog, or tracking any necessary LTI details that may be needed after the user completes a given task.

### lti_launch

The intent of this hook is to do the actual LTI launch. It is assumed in most cases that you will likely only want to implement one lti_launch hook as that process likely results in redirecting the user to the intended destination.

## API

### Show LTI Consumer

* URL:

    by id: `/wp-json/wp/v2/lti_credentials/34`
    
    by lumen guid: `/wp-json/wp/v2/lti_credentials?lumen_guid=abcdefg-0123-4567-890z-1337drl0sdd8`
    
    by title: `/wp-json/wp/v2/lti_credentials?search=microeconomics`
    
    by lti consumer key: `/wp-json/wp/v2/lti_credentials?lti_key=i-am-a-consumer-key`

* Content-Type:

    `application/json`
    
* Method:
    
    `GET`
    
* URL Params:

    Optional
    
    `lumen_guid=[string]`
    
* Success Response:

    * Code `200`
    
    * Content 
    
        ```
        {
            "id": 34,
            "date": "2018-05-22T22:18:39",
            "date_gmt": "2018-05-22T22:18:39",
            "status": "publish",
            "type": "lti_consumer",
            "title": {
                "rendered": "Microeconomics"
            },
            "_lti_consumer_key": "i-am-a-consumer-key",
            "_lumen_guid": "abcdefg-0123-4567-890z-1337drl0sdd8",
            
            ...
            
        },
        ...
        ```
