package com.example.salestracker

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView

class LogsAdapter : RecyclerView.Adapter<LogsAdapter.LogVH>() {

    private val items = mutableListOf<UiLog>()

    fun submit(newItems: List<UiLog>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): LogVH {
        val view = LayoutInflater.from(parent.context)
            .inflate(R.layout.item_log, parent, false)
        return LogVH(view)
    }

    override fun getItemCount(): Int = items.size

    override fun onBindViewHolder(holder: LogVH, position: Int) {
        holder.bind(items[position])
    }

    class LogVH(view: View) : RecyclerView.ViewHolder(view) {
        private val line1: TextView = view.findViewById(R.id.line1)
        private val line2: TextView = view.findViewById(R.id.line2)
        private val line3: TextView = view.findViewById(R.id.line3)

        fun bind(item: UiLog) {
            line1.text = item.line1
            line2.text = item.line2
            line3.text = item.line3
        }
    }
}
