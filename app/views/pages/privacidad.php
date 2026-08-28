<?php /** Política de privacidad — plantilla Ley 25.326. */ ?>
<section class="page-hero page-hero--sm">
  <div class="container page-hero__inner">
    <span class="eyebrow">Legales</span>
    <h1>Política de privacidad</h1>
    <p class="page-hero__subtitle">Última actualización: <?= date('d/m/Y') ?>. Cubre este sitio, la plataforma de seguimiento y la aplicación <strong>Satrak Campo</strong>.</p>
  </div>
</section>

<section class="section">
  <div class="container narrow legal">
    <p>En <strong>Satrak</strong> respetamos tu privacidad y nos comprometemos a proteger los datos personales que nos confiás, conforme a la <strong>Ley N.º 25.326 de Protección de los Datos Personales</strong> de la República Argentina y su normativa complementaria.</p>

    <p>Esta política cubre tres cosas distintas, y conviene distinguirlas porque no se rigen igual: <strong>(a)</strong> este sitio web y su formulario de contacto; <strong>(b)</strong> la plataforma de seguimiento que usan las empresas clientes; y <strong>(c)</strong> la aplicación móvil <strong>Satrak Campo</strong>, que instalan las personas que trabajan en esas empresas. Las secciones 1 a 7 se refieren al sitio. Las secciones 8 a 14 se refieren a la plataforma y a la app.</p>

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


    <h2>8. La plataforma y la app: quién es responsable de qué</h2>
    <p>Cuando una empresa contrata Satrak para hacer seguimiento de sus vehículos o de su personal, <strong>esa empresa es la responsable de la base de datos</strong> en los términos de la Ley N.º 25.326: es quien decide a quién se rastrea, con qué finalidad y durante qué horario. <strong>Satrak actúa como encargado del tratamiento</strong>: provee la tecnología y trata los datos únicamente siguiendo las instrucciones de la empresa, sin usarlos para fines propios.</p>
    <p>En la práctica esto significa que, si trabajás en una empresa que usa Satrak y querés acceder a tus datos, corregirlos o saber por qué se te rastrea, el primer canal es <strong>tu empleador</strong>. Aun así, podés escribirnos a <a href="mailto:<?= e($site['email']) ?>"><?= e($site['email']) ?></a> y te vamos a orientar.</p>

    <h2>9. Qué datos recopila la aplicación Satrak Campo</h2>
    <p>La app recopila exclusivamente lo necesario para prestar el servicio:</p>
    <ul>
      <li><strong>Ubicación precisa</strong> (latitud y longitud), junto con la velocidad, el rumbo y la precisión estimada de cada lectura del GPS. Se recopila <strong>también con la aplicación cerrada o el teléfono bloqueado</strong>, que es lo que permite registrar un recorrido completo.</li>
      <li><strong>Identificación laboral</strong>: el número de documento y el nombre con los que la persona inicia sesión, y la empresa a la que pertenece.</li>
      <li><strong>Datos del equipo</strong>: un identificador de la instalación, el modelo del teléfono, el sistema operativo, la versión de la app y el <strong>nivel de batería</strong>. La batería se usa para avisarle al operador que un equipo está por quedarse sin carga en medio de la jornada.</li>
      <li><strong>Eventos</strong>: activaciones del botón de pánico, inicio y llegada de misiones, y pérdidas del permiso de ubicación.</li>
    </ul>
    <p>La aplicación <strong>no accede</strong> a los contactos, las fotos, el micrófono, la cámara, los mensajes ni el historial de navegación del teléfono.</p>

    <h2>10. Cuándo se recopila: sólo durante la jornada</h2>
    <p>Este es el límite central del servicio. La ubicación se registra <strong>únicamente dentro del horario de trabajo que la empresa configuró</strong> para esa persona, incluidos los turnos que cruzan la medianoche y las excepciones puntuales que se hayan cargado.</p>
    <p><strong>Fuera de ese horario la posición se descarta, no se guarda.</strong> La restricción se aplica en dos capas independientes: la aplicación no captura fuera de turno, y el servidor descarta cualquier posición que igual le llegue. Una persona sin jornada configurada no es rastreada en ningún momento.</p>
    <p>La excepción es el <strong>botón de pánico</strong>: si la empresa lo tiene habilitado, se acepta siempre, dentro o fuera de la jornada, porque una emergencia no espera al horario.</p>

    <h2>11. Para qué se usan</h2>
    <p>Los datos se usan para: mostrar la posición en el panel de la empresa, reconstruir recorridos y jornadas, generar alertas operativas (exceso de velocidad, salida del puesto asignado, falta de movimiento, batería baja, pánico) y elaborar reportes de actividad.</p>
    <p><strong>No se usan para publicidad. No se venden ni se ceden a terceros. No se cruzan con datos de otras aplicaciones ni empresas</strong> con fines de perfilado o seguimiento comercial.</p>

    <h2>12. Cuánto tiempo se conservan</h2>
    <p>Las posiciones y los eventos de dispositivo se conservan <strong>12 meses</strong> y luego se eliminan automáticamente. Los datos de la persona (nombre, documento, jornada) se conservan mientras dure la relación con la empresa cliente y por los plazos que exija la normativa laboral aplicable.</p>

    <h2>13. Consentimiento y transparencia hacia la persona</h2>
    <p>La empresa cliente es responsable de informar a su personal que va a ser objeto de seguimiento, de explicarle con qué finalidad y de obtener el consentimiento cuando corresponda. La plataforma registra la fecha de ese consentimiento.</p>
    <p>La aplicación muestra <strong>en su pantalla principal y de forma permanente</strong> si la persona está o no dentro de la jornada, es decir, si en ese momento se está registrando su ubicación. Mientras el seguimiento está activo, el sistema operativo del teléfono muestra además una notificación fija. No hay seguimiento silencioso.</p>

    <h2>14. Seguridad de los datos de seguimiento</h2>
    <p>El acceso a los datos de cada empresa está aislado del de las demás. Las comunicaciones entre la aplicación y el servidor viajan cifradas. Las contraseñas se almacenan con algoritmos de hash diseñados para ese fin, nunca en texto legible, y las sesiones de la aplicación pueden revocarse en cualquier momento desde el panel de la empresa.</p>

    <p class="note">Las secciones 8 a 14 describen el funcionamiento real del sistema y son las que exigen Google Play y App Store para publicar la aplicación. Este texto no constituye asesoramiento legal: conviene que lo revise un profesional antes de operar con clientes reales.</p>
  </div>
</section>
