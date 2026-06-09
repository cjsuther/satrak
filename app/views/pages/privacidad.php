<?php /** Política de privacidad — plantilla Ley 25.326. */ ?>
<section class="page-hero page-hero--sm">
  <div class="container page-hero__inner">
    <span class="eyebrow">Legales</span>
    <h1>Política de privacidad</h1>
    <p class="page-hero__subtitle">Última actualización: <?= date('d/m/Y') ?>. Texto modelo — reemplazar/ajustar con el texto definitivo provisto por Satrak.</p>
  </div>
</section>

<section class="section">
  <div class="container narrow legal">
    <p>En <strong>Satrak</strong> respetamos tu privacidad y nos comprometemos a proteger los datos personales que nos confiás, conforme a la <strong>Ley N.º 25.326 de Protección de los Datos Personales</strong> de la República Argentina y su normativa complementaria.</p>

    <h2>1. Responsable del tratamiento</h2>
    <p>El responsable de la base de datos es Satrak, con domicilio en <?= e($site['direccion']) ?>. Para cualquier consulta sobre tus datos podés escribirnos a <a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a>.</p>

    <h2>2. Datos que recopilamos</h2>
    <p>A través del formulario de contacto recopilamos los datos que vos nos proporcionás voluntariamente: nombre y apellido, email, teléfono, empresa (opcional), servicio de interés, cantidad de unidades y el mensaje que nos envíes. Asimismo, por motivos de seguridad podemos registrar tu dirección IP y datos técnicos de tu navegador.</p>

    <h2>3. Finalidad</h2>
    <p>Los datos se utilizan exclusivamente para: (a) responder tu consulta y elaborar una cotización; (b) contactarte por los canales que indicaste; (c) gestionar la relación comercial. No vendemos ni cedemos tus datos a terceros con fines comerciales ajenos a Satrak.</p>

    <h2>4. Conservación</h2>
    <p>Conservamos tus datos durante el tiempo necesario para cumplir las finalidades descriptas y las obligaciones legales aplicables.</p>

    <h2>5. Derechos del titular</h2>
    <p>Como titular de los datos, tenés derecho a acceder, rectificar, actualizar y suprimir tus datos personales. Para ejercerlos, escribinos a <a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a>.</p>
    <p>La <strong>AGENCIA DE ACCESO A LA INFORMACIÓN PÚBLICA</strong>, órgano de control de la Ley N.º 25.326, tiene la atribución de atender las denuncias y reclamos que se interpongan con relación al incumplimiento de las normas sobre protección de datos personales.</p>

    <h2>6. Seguridad</h2>
    <p>Adoptamos medidas técnicas y organizativas razonables para proteger tus datos contra el acceso no autorizado, la alteración o la pérdida.</p>

    <h2>7. Cambios</h2>
    <p>Podemos actualizar esta política. Publicaremos cualquier cambio en esta misma página con su fecha de actualización.</p>

    <p class="note">Este texto es una plantilla orientativa y no constituye asesoramiento legal. Debe ser revisado y completado por Satrak antes de su publicación definitiva.</p>
  </div>
</section>
