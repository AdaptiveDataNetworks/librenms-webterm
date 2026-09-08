{{-- Fallback layout.

     The console extends LibreNMS's own layouts.librenmsv1 so that it carries
     the normal chrome, menu and asset bundle. That view belongs to core and is
     absent outside a LibreNMS installation, which would make the console
     impossible to render -- and therefore to test -- standalone. @extendsFirst
     picks core's layout when it exists and this when it does not. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'WebTerm')</title>
</head>
<body>
@yield('content')
</body>
</html>
