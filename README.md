# AKSO Bridge Plugin
This plugin provides extensions to [Grav CMS](https://github.com/getgrav/grav) for including content from AKSO.

This plugin adds several [markdown extensions](markdown.md).

## Installation

Download and install Grav. Once you have Grav installed, navigate to the plugins folder in your Grav installation. For example:

```sh
cd <GRAV-ROOT>/user/plugins/
```

In the plugins folder, download the Akso Bridge plugin by cloning the repository from GitHub. Use the following command:

```sh
git clone https://github.com/AksoEo/grav-akso-bridge.git
```

This command will download the repository and create a folder named grav-akso-bridge. Rename the folder to `akso-bridge`:

```sh
mv grav-akso-bridge akso-bridge
```

Navigate to the akso-bridge folder and build the plugin. Enter the following command:

```sh
./build.sh
```

Go to the `aksobridged` folder located within the `akso-bridge` folder. Start the Akso daemon by running the following command:

```sh
node .
```

If you're running this in a production environment, you should consider using a process manager like PM2.

Once you followed those steps, your plugin is ready to use. You might want to use [akso-grav-theme](https://github.com/AksoEo/akso-grav-theme) together with it.

