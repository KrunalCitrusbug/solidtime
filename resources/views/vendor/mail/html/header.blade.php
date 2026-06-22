@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-align: center;">
@if(trim($slot) === 'solidtime')
{{-- Absolute, publicly reachable URLs so images render in any mail client. --}}
<img src="https://citrusbug.tech.blog/wp-content/uploads/2019/11/citrusfulllogo.png" alt="Citrusbug Technolabs" style="height: 44px; width: auto; max-width: 100%; display: inline-block;">
<br>
<img src="https://avatars.githubusercontent.com/u/156823565?v=4" alt="Solidtime" style="height: 24px; width: 24px; border-radius: 4px; margin-top: 8px; display: inline-block;">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>
