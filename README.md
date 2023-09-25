# OMP VG Wort Plugin

The VG Wort plugin enables integration and management of the [VG Wort](https://www.vgwort.de/startseite.html) pixel tags
in [OJS](https://pkp.sfu.ca/ojs/). Here you can assign the pixel tags to the articles, and register them
automatically (using a cron job or AcronPlugin) or manually.

The plugin is a part of the project [OJS-de.net](http://www.ojs-de.net).

## Getting Started

#### Installation via OMP GUI

1. Download `vgWort-[version]-.tar.gz` from [GitHub](https://github.com/UB-Heidelberg/vgWort/).
2. Install the plugin in OMP.

#### Installation via command line without Git

1. Download the `.tar.gz` archive from [GitHub](https://github.com/UB-Heidelberg/vgWort).
2. Go to the folder of your OMP instance and unzip the archive.
3. Rename the main plugin folder to "vgWort" if necessary.

#### Installation via command line with Git

1. Go to the folder of your OMP instance and clone the repository.

    ```console
    $ cd [path/to/your/omp]/plugins/generic
    $ git clone https://github.com/UB-Heidelberg/vgWort
    ```

2. Switch to the vgWort directory and checkout the branch.

    ```console
    $ cd vgWort
    $ git checkout omp-stable-3_4_0
    ```

## License

This plugin is licensed under the GNU General Public License v2. See the file [LICENSE](LICENSE) for the complete terms of this license.

## System Requirements

This plugin version is compatible with OMP 3.4.0.

## Version History

* 1.0 &ndash; Initial Release
* 1.1 &ndash; Updated to support OJS 2.4.1
* 1.2 &ndash; Updated to support OJS 2.4.2
* 1.3 &ndash; Insert already registered pixel tags
* 1.4 &ndash; Very important fix - use VG Wort live instead of test server
* 1.5 &ndash; VG Wort test system in plugin settings,
* 1.6 &ndash; Fix a major error: all authors and translators has to be registered at VG Wort. Also: add the possibility to enter translators and to remove a registration.
* 1.7 &ndash; Plugin version for OJS 3.1.1-4
* 2.0 &ndash; Plugin version for OJS 3.1.2
* 2.1 &ndash; Plugin version for OJS 3.2.1

## Contact

Documentation, bug listings, and updates can be found on this plugin's homepage
at [GitHub](http://github.com/ojsde/vgWort).

