# GLTFHandler
GLTFHandler lets you upload and interact with GLTF models on MediaWiki. It uses [google/model-viewer](https://github.com/google/model-viewer) library to
display the 3D models and optionally view them in your environment using augmented reality (on supported devices).

For developers, a documentation for the glTF parser can be found [here](src/Parser/README.md).

## Motive
Existing 3D extensions in the MediaWiki ecosphere do not support the glTF format which has become a standard for
scenes and models. glTF libraries in PHP are lacking if not non-existent, requiring wiki maintainers to install third
party libraries in addition to the extension for validating 3D assets. This makes the installation process inconvenient,
and at times sufficiently challenging.

## Features
- Work out of the box—no dependency installation needed
- Support .gltf and .glb files
- Validate structure of .gltf and .glb files
- Bounding box calculation to properly size the output canvas
- Use [google/model-viewer](https://github.com/google/model-viewer) library to render 3D models, which supports all evergreen desktop and mobile browsers—Chrome, Firefox, Safari, and Edge.
- Allow custom output options for model-viewer (see [usage](#Usage))

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

<table>
    <tr>
        <th colspan="2">Camera Properties</th>
    </tr>
    <tr>
        <th>Parameter</th>
        <th>Description</th>
    </tr>
    <tr>
        <td><code>camera-orbit</code></td>
        <td>
            Set the starting and/or subsequent orbital position of the camera. You can control the azimuthal, theta, and polar, phi, angles (phi is measured down from the top), and the radius from the center of the model. Accepts values of the form "<code>$theta $phi $radius</code>", like <code>camera-orbit="-10deg 75deg 1.5m"</code>. Also supports units in radians (<code>rad</code>) for angles and centimeters (<code>cm</code>) or millimeters (<code>mm</code>) for camera distance. Camera distance can also be set as a percentage (<code>%</code>), where 100% gives the model tight framing within any window based on all possible theta and phi values.<br/>
            <i>Pertains to model-viewer's <code>camera-orbit</code> attribute.</i>
        </td>
    </tr>
    <tr>
        <td><code>max-camera-orbit</code></td>
        <td>
            Set the maximum orbital values of the camera. Takes values in the same form as <code>camera-orbit</code>.<br/>
            <i>Pertains to model-viewer's <code>max-camera-orbit</code> attribute.</i>
        </td>
    </tr>
</table>

<table>
    <tr>
        <th colspan="2">Skybox Properties</th>
    </tr>
    <tr>
        <th>Parameter</th>
        <th>Description</th>
    </tr>
    <tr>
        <td><code>environment</code></td>
        <td>
            A .hdr or .jpg file. Controls the environmental reflection of the model.<br/>
            <i>Pertains to model-viewer's <code>environment-image</code> attribute.</i>
        </td>
    </tr>
    <tr>
        <td><code>skybox</code></td>
        <td>
            An equirectangular projection image (.png, .hdr, .jpg). Sets the background image of the scene.<br/>
            <i>Pertains to model-viewer's <code>skybox-image</code> attribute.</i>
        </td>
    </tr>
    <tr>
        <td><code>skybox-height</code></td>
        <td>
            Causes the skybox to be projected onto the ground plane. The height indicates the camera's distance above the ground and acts to scale the image at ground level to the correct size. Accepts units in meters (<code>m</code>), centimeters (<code>cm</code>), or millimeters (<code>mm</code>). The default value of <code>0m</code> disables ground projection.<br/>
            <i>Pertains to model-viewer's <code>skybox-height</code> attribute.</i>
        </td>
    </tr>
</table>

<table>
    <tr>
        <th colspan="2">Animation Properties</th>
    </tr>
    <tr>
        <th>Parameter</th>
        <th>Description</th>
    </tr>
    <tr>
        <td><code>animation-name</code></td>
        <td>
            Selects an animation to play by name. If not specified, the first animation is implicitly selected.<br/>
            <i>Pertains to model-viewer's <code>animation-name</code> attribute.</i>
        </td>
    </tr>
    <tr>
        <td><code>autoplay</code></td>
        <td>
            If the model has animations, the selected animation will automatically begin to play.<br/>
            <i>Pertains to model-viewer's <code>autoplay</code> attribute.</i>
        </td>
    </tr>
</table>

<table>
    <tr>
        <th colspan="2">Other Properties</th>
    </tr>
    <tr>
        <th>Parameter</th>
        <th>Description</th>
    </tr>
    <tr>
        <td><code>ar</code></td>
        <td>
            Enable the ability to launch AR experiences on supported devices.<br/>
            <i>Pertains to model-viewer's <code>ar</code> attribute.</i>
        </td>
    </tr>
    <tr>
        <td><code>poster</code></td>
        <td>
            An image file. Displays an image instead of the model, useful for showing the user something before a model is loaded and ready to render.<br/>
            <i>Pertains to model-viewer's <code>poster</code> attribute.</i>
        </td>
    </tr>
</table>

Example usage of optional parameters:
```
[[File:MyModel.glb|ar|autoplay|environment=SomeEnvironment.png|poster=SomePoster.png]]
[[File:MyModel.glb|camera-orbit=-30deg 90deg 22|skybox=SomeSkybox.jpg|skybox-height=1.5m]]
[[File:MyModel.glb|autoplay|animation-name=Running]]
```

## Limitations
- A glTF file containing a local or a remote URI is disallowed.
  This is both a safety mechanism (in case a local URI does not exist) and a security mechanism (to avoid loading unsafe
  assets at the user and the server's end).
- No thumbnail generation at its current stage.
  In short, by thumbnail we mean generating a preview image (e.g., a PNG) for the glTF model. Supporting this will most
  likely break portability of this extension. But this is much needed for 1) broader site accessibility, and 2) for
  OpenGraph tags as they do not support 3D assets in embeds.
- Limitations imposed by the [parser](src/Parser/README.md#Limitations).

## Gallery
<p float="left">
   <img src="https://github.com/user-attachments/assets/fcb849c2-d892-41cc-98aa-2fcb8af32877" width="250px"/>
   <img src="https://github.com/user-attachments/assets/3235b111-cf74-433c-b5fd-d13177bcb9c5" width="250px"/>
   <img src="https://github.com/user-attachments/assets/7f9b2dcf-63bd-4279-bf03-1e227a58cd3c" width="250px"/>
</p>
