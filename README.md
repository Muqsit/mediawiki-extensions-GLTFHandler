# GLTFHandler
GLTFHandler lets you upload and interact with GLTF models on MediaWiki.
It uses [google/model-viewer](https://github.com/google/model-viewer) library to display the 3D models and view them in
your environment using augmented reality (on supported devices).

## Features
- Support .gltf and .glb files
- Allow custom output options for model-viewer
- Use [google/model-viewer](https://github.com/google/model-viewer) library to render 3D models, which supports all evergreen desktop and mobile browsers—Chrome, Firefox, Safari, and Edge.

## Installation
Requires **MediaWiki 1.42.0** or later.
1. Download GLTFHandler extension. You can get the extension via Git (specifying GLTFHandler as the destination directory):
   ```
   git clone https://github.com/Muqsit/mediawiki-extensions-GLTFHandler.git GLTFHandler
   ```
   Or [download it as zip archive](https://github.com/Muqsit/mediawiki-extensions-GLTFHandler/archive/master.zip).

   In either case, the "GLTFHandler" extension should end up in the "extensions" directory of your MediaWiki installation.
   If you got the zip archive, you will need to put it into a directory called GLTFHandler.
2. Add the following code at the bottom of your LocalSettings.php:
   ```php
   wfLoadExtension( 'GLTFHandler' );
   ```
3. ✔️**Done**—Navigate to Special:Version on your wiki to verify that the extension is successfully installed.

## Usage
GLTFHandler uses the exact syntax as your ordinary media files:
```
[[File:MyModel.glb]]
[[File:MyModel.gltf]]
[[File:MyModel.glb|thumb|400px]]
```
At the moment, the following file parameters are supported. See [model-viewer documentataion](https://modelviewer.dev/docs/index.html) for live examples. All parameters are optional.

| Parameter       | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
|:----------------|:--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ar`            | Enable the ability to launch AR experiences on supported devices.<br/><i>Pertains to model-viewer's `ar` attribute.</i>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `camera-orbit`  | Set the starting and/or subsequent orbital position of the camera. You can control the azimuthal, theta, and polar, phi, angles (phi is measured down from the top), and the radius from the center of the model. Accepts values of the form "\$theta \$phi \$radius", like `camera-orbit=-10deg 75deg 1.5m`. Also supports units in radians ("rad") for angles and centimeters ("cm") or millimeters ("mm") for camera distance. Camera distance can also be set as a percentage ('%'), where 100% gives the model tight framing within any window based on all possible theta and phi values.<br/><i>Pertains to model-viewer's `camera-orbit` attribute.</i>                                                                                                                                                                                                                             
| `environment`   | A .hdr or .jpg file. Controls the environmental reflection of the model.<br/><i>Pertains to model-viewer's `environment-image` attribute.</i>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| `poster`        | An image file. Displays an image instead of the model, useful for showing the user something before a model is loaded and ready to render..<br/><i>Pertains to model-viewer's `poster` attribute.</i>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `skybox`        | An equirectangular projection image (.png, .hdr, .jpg). Sets the background image of the scene.<br/><i>Pertains to model-viewer's `skybox-image` attribute.</i>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `skybox-height` | Causes the skybox to be projected onto the ground plane. The height indicates the camera's distance above the ground and acts to scale the image at ground level to the correct size. Accepts units in meters ("m"), centimeters ("cm"), or millimeters ("mm"). The default value of 0m disables ground projection.<br/><i>Pertains to model-viewer's `skybox-height` attribute.</i>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |

Example usage of optional parameters:
```
[[File:MyModel.glb|ar|environment=SomeEnvironment.png|poster=SomePoster.png]]
[[File:MyModel.glb|camera-orbit=-30deg 90deg 22|skybox=SomeSkybox.jpg|skybox-height=1.5m]]
```