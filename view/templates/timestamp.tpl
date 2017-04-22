
{{* This template does insert data with the timestamp of the last item wich does arrive
 It is used to transfer the timestamp so javascript query for it *}}
<div {{if $id}}id="{{$id}}"{{/if}} data-time="{{$timestamp}}" style="display: none;"></div>
