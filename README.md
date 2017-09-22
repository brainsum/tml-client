# Tieto Media Library - Filefield source module
Define 'TML Remote URL textfield' filefield source.
  
## Configuration
- Set TML Rest API URI at form widget settings.
- Add TML Rest API basic auth credentials to your settings.php:

      /**
      * TML entity browser crdetials settings.
      */
      $config['tml_filefield_sources']['username'] = 'USERNAME';
      $config['tml_filefield_sources']['password'] = 'PASSWORD';

